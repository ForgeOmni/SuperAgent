<?php

namespace SuperAgent\Tests\Unit\AdaptiveFeedback;

use PHPUnit\Framework\TestCase;
use SuperAgent\AdaptiveFeedback\AdaptiveFeedbackEngine;
use SuperAgent\AdaptiveFeedback\CorrectionCategory;
use SuperAgent\AdaptiveFeedback\CorrectionStore;

class AdaptiveFeedbackEngineTest extends TestCase
{
    private CorrectionStore $store;

    protected function setUp(): void
    {
        $this->store = new CorrectionStore(null);
    }

    private function makeEngine(int $threshold = 3, bool $autoPromote = false): AdaptiveFeedbackEngine
    {
        return new AdaptiveFeedbackEngine(
            store: $this->store,
            promotionThreshold: $threshold,
            autoPromote: $autoPromote,
        );
    }

    private function recordN(CorrectionCategory $cat, string $pattern, int $n, ?string $tool = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->store->record($cat, $pattern, "reason {$i}", $tool);
        }
    }

    // ── Evaluation ─────────────────────────────────────────────────

    public function test_evaluate_no_promotable(): void
    {
        $engine = $this->makeEngine(3);

        $this->recordN(CorrectionCategory::TOOL_DENIED, 'test', 2);

        $results = $engine->evaluate();
        $this->assertEmpty($results);
    }

    public function test_evaluate_promotes_tool_denied_to_rule(): void
    {
        $engine = $this->makeEngine(3);

        $this->recordN(CorrectionCategory::TOOL_DENIED, 'bash: rm -rf', 3, 'Bash');

        $results = $engine->evaluate();
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isRule());
        $this->assertStringContainsString('adaptive_', $results[0]->content);
        $this->assertStringContainsString('warn', $results[0]->content);
    }

    public function test_evaluate_promotes_behavior_correction_to_memory(): void
    {
        $engine = $this->makeEngine(3);

        $this->recordN(CorrectionCategory::BEHAVIOR_CORRECTION, 'stop adding docstrings', 3);

        $results = $engine->evaluate();
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isMemory());
        $this->assertStringContainsString('stop adding docstrings', $results[0]->content);
        $this->assertStringContainsString('Why:', $results[0]->content);
    }

    public function test_evaluate_promotes_content_unwanted_to_memory(): void
    {
        $engine = $this->makeEngine(2);

        $this->recordN(CorrectionCategory::CONTENT_UNWANTED, 'unnecessary comments', 2);

        $results = $engine->evaluate();
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isMemory());
    }

    public function test_evaluate_promotes_edit_reverted_to_rule(): void
    {
        $engine = $this->makeEngine(3);

        $this->recordN(CorrectionCategory::EDIT_REVERTED, 'edit reverted: .env', 3, 'Edit');

        $results = $engine->evaluate();
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isRule());
    }

    public function test_high_frequency_gets_deny_instead_of_warn(): void
    {
        $engine = $this->makeEngine(3);

        // 6 occurrences = 2x threshold → deny instead of warn
        $this->recordN(CorrectionCategory::TOOL_DENIED, 'bash: rm', 6, 'Bash');

        $results = $engine->evaluate();
        $this->assertCount(1, $results);
        $this->assertStringContainsString('deny', $results[0]->content);
    }

    public function test_promoted_pattern_not_re_promoted(): void
    {
        $engine = $this->makeEngine(3);

        $this->recordN(CorrectionCategory::TOOL_DENIED, 'test', 3, 'Bash');

        $first = $engine->evaluate();
        $this->assertCount(1, $first);

        $second = $engine->evaluate();
        $this->assertEmpty($second);
    }

    // ── Promote By ID ──────────────────────────────────────────────

    public function test_promote_by_id(): void
    {
        $engine = $this->makeEngine(100); // High threshold — won't auto-promote

        $p = $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'test', 'r1');

        $result = $engine->promoteById($p->id);
        $this->assertNotNull($result);
        $this->assertTrue($result->isMemory());
    }

    public function test_promote_by_id_nonexistent(): void
    {
        $engine = $this->makeEngine();
        $this->assertNull($engine->promoteById('nonexistent'));
    }

    public function test_promote_by_id_already_promoted(): void
    {
        $engine = $this->makeEngine(3);
        $this->recordN(CorrectionCategory::TOOL_DENIED, 'test', 3, 'Bash');

        $engine->evaluate(); // Promote

        $pattern = $this->store->getAll()[0];
        $this->assertNull($engine->promoteById($pattern->id));
    }

    // ── Suggestions ────────────────────────────────────────────────

    public function test_get_suggestions(): void
    {
        $engine = $this->makeEngine(5);

        $this->recordN(CorrectionCategory::TOOL_DENIED, 'approaching', 3);
        $this->recordN(CorrectionCategory::TOOL_DENIED, 'far-away', 1);

        $suggestions = $engine->getSuggestions();
        $this->assertCount(1, $suggestions);
        $this->assertSame(2, $suggestions[0]['remaining']);
    }

    // ── Statistics ─────────────────────────────────────────────────

    public function test_statistics(): void
    {
        $engine = $this->makeEngine(3);

        $this->recordN(CorrectionCategory::TOOL_DENIED, 'test', 4, 'Bash');

        $stats = $engine->getStatistics();
        $this->assertSame(3, $stats['promotion_threshold']);
        $this->assertSame(1, $stats['promotable_count']);
        $this->assertSame(1, $stats['total_patterns']);
    }

    // ── Event Listeners ────────────────────────────────────────────

    public function test_promotion_events(): void
    {
        $events = [];
        $engine = $this->makeEngine(3);

        $engine->on('feedback.promoted', function ($result) use (&$events) {
            $events[] = "promoted:{$result->type}";
        });
        $engine->on('feedback.rule_generated', function ($result) use (&$events) {
            $events[] = 'rule';
        });

        $this->recordN(CorrectionCategory::TOOL_DENIED, 'test', 3, 'Bash');
        $engine->evaluate();

        $this->assertContains('promoted:rule', $events);
        $this->assertContains('rule', $events);
    }

    // ── Rule Content ───────────────────────────────────────────────

    public function test_rule_contains_tool_condition(): void
    {
        $engine = $this->makeEngine(3);

        $this->recordN(CorrectionCategory::TOOL_DENIED, 'bash: git push --force', 3, 'Bash');

        $results = $engine->evaluate();
        $this->assertStringContainsString('tool:', $results[0]->content);
        $this->assertStringContainsString('Bash', $results[0]->content);
    }

    // ── Memory Content ─────────────────────────────────────────────

    public function test_memory_content_structure(): void
    {
        $engine = $this->makeEngine(2);

        $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'stop using var', 'Prefer const');
        $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'stop using var', 'Use let instead');

        $results = $engine->evaluate();
        $content = $results[0]->content;

        $this->assertStringContainsString('stop using var', $content);
        $this->assertStringContainsString('Why:', $content);
        $this->assertStringContainsString('How to apply:', $content);
        $this->assertStringContainsString('Prefer const', $content);
    }
}
