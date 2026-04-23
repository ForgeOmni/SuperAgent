<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;
use SuperAgent\Guardrails\LoopDetector;
use SuperAgent\Guardrails\LoopType;

/**
 * Five detectors, five scenario groups. Port of qwen-code's
 * loopDetectionService test suite behavior — we don't copy their
 * tests directly (TS / Gemini-specific event types) but we preserve
 * the same thresholds and the cold-start exemption logic.
 */
class LoopDetectorTest extends TestCase
{
    public function test_tool_loop_fires_after_5_identical_calls(): void
    {
        $d = new LoopDetector();
        // Bake in a non-read tool first so cold-start doesn't mask
        // anything. (TOOL_LOOP ignores cold-start, but we want a
        // consistent starting state across tests.)
        $d->observeToolCall('Bash', ['command' => 'ls']);

        for ($i = 0; $i < 4; $i++) {
            $v = $d->observeToolCall('Edit', ['file' => '/a.md', 'line' => 1]);
            $this->assertNull($v, "iteration {$i} should not trip yet");
        }
        // 5th identical call crosses the threshold.
        $v = $d->observeToolCall('Edit', ['file' => '/a.md', 'line' => 1]);
        $this->assertNotNull($v);
        $this->assertSame(LoopType::ToolLoop, $v->type);
        $this->assertSame('Edit', $v->metadata['tool']);
    }

    public function test_tool_loop_ignores_key_order_in_args(): void
    {
        // {a:1,b:2} and {b:2,a:1} must be treated as identical — the
        // detector normalizes via canonicalArgs() (recursive ksort).
        $d = new LoopDetector();
        $d->observeToolCall('Bash', ['command' => 'ls']); // warm cold-start
        $d->observeToolCall('Edit', ['a' => 1, 'b' => 2]);
        $d->observeToolCall('Edit', ['b' => 2, 'a' => 1]);
        $d->observeToolCall('Edit', ['a' => 1, 'b' => 2]);
        $d->observeToolCall('Edit', ['b' => 2, 'a' => 1]);
        $v = $d->observeToolCall('Edit', ['a' => 1, 'b' => 2]);
        $this->assertNotNull($v);
        $this->assertSame(LoopType::ToolLoop, $v->type);
    }

    public function test_changing_args_breaks_tool_loop_streak(): void
    {
        $d = new LoopDetector();
        $d->observeToolCall('Bash', ['command' => 'ls']);
        for ($i = 0; $i < 5; $i++) {
            $v = $d->observeToolCall('Edit', ['file' => "/file-{$i}.md"]);
            $this->assertNull($v, "changing args must not trip TOOL_LOOP (iter {$i})");
        }
    }

    public function test_stagnation_fires_after_8_same_name_calls_with_varying_args(): void
    {
        // STAGNATION catches the parameter-thrashing variant — same
        // name, different args each time. TOOL_LOOP wouldn't catch
        // this because it requires argument equality.
        $d = new LoopDetector();
        $d->observeToolCall('Bash', ['command' => 'ls']);  // warm cold-start
        for ($i = 1; $i <= 7; $i++) {
            $v = $d->observeToolCall('Grep', ['pattern' => "p-{$i}"]);
            $this->assertNull($v, "iter {$i} should not trip yet");
        }
        $v = $d->observeToolCall('Grep', ['pattern' => 'p-8']);
        $this->assertNotNull($v);
        $this->assertSame(LoopType::Stagnation, $v->type);
    }

    // ── FILE_READ_LOOP + cold-start ────────────────────────────────

    public function test_cold_start_exempts_opening_file_read_loop(): void
    {
        // Opening exploration uses multiple read-like tools
        // (list_directory + read_file, alternating). Neither TOOL_LOOP
        // (different args) nor STAGNATION (different names, never same
        // name twice in a row) should fire — the only detector that
        // COULD plausibly fire for a "reading everything in sight"
        // burst is FILE_READ_LOOP, and cold-start exempts it until a
        // non-read tool lands.
        $d = new LoopDetector();
        $reads = ['list_directory', 'read_file'];
        for ($i = 0; $i < 20; $i++) {
            $tool = $reads[$i % 2];
            $v = $d->observeToolCall($tool, ['path' => "/src/f{$i}"]);
            $this->assertNull($v, "cold-start read {$i} (tool={$tool}) must not trip anything");
        }
        $this->assertFalse($d->hasSeenNonReadToolCall());
    }

    public function test_file_read_loop_fires_after_cold_start_gate_lifted(): void
    {
        // Cold-start gate lifts on first non-read tool. After that, a
        // window dominated by reads trips FILE_READ_LOOP. Alternate
        // list_directory and read_file so we don't accidentally trip
        // STAGNATION (same-name streak) first.
        $d = new LoopDetector();
        $d->observeToolCall('Bash', ['command' => 'git status']);  // lift cold-start

        $reads = ['list_directory', 'read_file'];
        $v = null;
        for ($i = 0; $i < 15; $i++) {
            $tool = $reads[$i % 2];
            $v = $d->observeToolCall($tool, ['path' => "/src/f{$i}"]);
            if ($v !== null) {
                break;
            }
        }
        $this->assertNotNull($v);
        $this->assertSame(LoopType::FileReadLoop, $v->type);
        $this->assertGreaterThanOrEqual(8, $v->metadata['reads']);
    }

    public function test_read_like_helper_matches_expected_names(): void
    {
        foreach (['read_file', 'read_many_files', 'list_directory', 'Read', 'Grep', 'Glob'] as $n) {
            $this->assertTrue(LoopDetector::isReadLike($n), "'{$n}' should be classed as read-like");
        }
        // MCP convention prefixes.
        foreach (['read_resource', 'list_projects', 'READ_notes'] as $n) {
            $this->assertTrue(LoopDetector::isReadLike($n));
        }
        // Tools that coincidentally contain those letters but aren't reads.
        foreach (['Review', 'Bash', 'Edit', 'Write', 'reviewer_check', 'listener'] as $n) {
            $this->assertFalse(
                LoopDetector::isReadLike($n),
                "'{$n}' must NOT be classed as read-like",
            );
        }
    }

    // ── Content / thought ─────────────────────────────────────────

    public function test_content_loop_fires_on_repeated_50_char_chunks(): void
    {
        $d = new LoopDetector();
        $phrase = str_repeat('A', 50);  // exactly 50 chars
        // 10 occurrences = threshold.
        $v = null;
        for ($i = 0; $i < 10; $i++) {
            $v = $d->observeContent($phrase);
        }
        $this->assertNotNull($v);
        $this->assertSame(LoopType::ContentLoop, $v->type);
    }

    public function test_varied_content_does_not_trip_content_loop(): void
    {
        // Each streamed delta is a distinct 100-char paragraph. The
        // sliding-window hash never repeats.
        $d = new LoopDetector();
        for ($i = 0; $i < 50; $i++) {
            $delta = "Paragraph #{$i}: " . str_repeat(chr(65 + ($i % 26)), 80) . "\n";
            $v = $d->observeContent($delta);
            $this->assertNull($v, "iter {$i} (unique deltas) must not trip CONTENT_LOOP");
        }
    }

    public function test_sub_chunk_size_content_never_trips(): void
    {
        // Less than CHUNK_SIZE total content can't have a repeating
        // 50-char window. Documents the lower bound.
        $d = new LoopDetector();
        for ($i = 0; $i < 40; $i++) {
            $this->assertNull($d->observeContent('a'));
        }
    }

    public function test_thought_loop_fires_at_threshold_3(): void
    {
        $d = new LoopDetector();
        $this->assertNull($d->observeThought('  Considering approach A  '));
        $this->assertNull($d->observeThought('Considering approach A'));  // whitespace-normalized dup
        $v = $d->observeThought('Considering approach A');
        $this->assertNotNull($v);
        $this->assertSame(LoopType::ThoughtLoop, $v->type);
    }

    public function test_different_thoughts_do_not_trip(): void
    {
        $d = new LoopDetector();
        foreach (['A', 'B', 'C', 'D', 'E', 'A', 'B'] as $t) {
            $this->assertNull($d->observeThought($t));
        }
    }

    // ── Sticky violation + reset ──────────────────────────────────

    public function test_violation_is_sticky_until_reset(): void
    {
        $d = new LoopDetector();
        $d->observeToolCall('Bash', ['command' => 'ls']);
        for ($i = 0; $i < 5; $i++) {
            $d->observeToolCall('Edit', ['file' => '/x']);
        }
        $v1 = $d->lastViolation();
        $this->assertNotNull($v1);

        // Subsequent observations keep returning the cached violation.
        $v2 = $d->observeToolCall('Read', ['path' => '/new']);
        $this->assertSame($v1, $v2);
        $v3 = $d->observeContent('different content');
        $this->assertSame($v1, $v3);

        $d->reset();
        $this->assertNull($d->lastViolation());
        $this->assertNull($d->observeToolCall('Read', ['path' => '/fresh']));
    }

    // ── Config overrides ──────────────────────────────────────────

    public function test_threshold_overrides_apply(): void
    {
        $d = new LoopDetector(['TOOL_CALL_LOOP_THRESHOLD' => 2]);
        $d->observeToolCall('Bash', ['command' => 'ls']);
        $d->observeToolCall('Edit', ['f' => '/a']);
        $v = $d->observeToolCall('Edit', ['f' => '/a']);  // count=2 — trips at override
        $this->assertNotNull($v);
        $this->assertSame(LoopType::ToolLoop, $v->type);
    }

    public function test_unknown_override_keys_are_ignored(): void
    {
        $d = new LoopDetector(['NONEXISTENT_KEY' => 999]);   // must not throw
        $this->assertNull($d->observeToolCall('Bash', ['command' => 'ls']));
    }
}
