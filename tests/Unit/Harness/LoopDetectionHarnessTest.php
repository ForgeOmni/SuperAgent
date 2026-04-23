<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Harness;

use PHPUnit\Framework\TestCase;
use SuperAgent\Guardrails\LoopDetector;
use SuperAgent\Guardrails\LoopType;
use SuperAgent\Guardrails\LoopViolation;
use SuperAgent\Harness\LoopDetectedEvent;
use SuperAgent\Harness\LoopDetectionHarness;
use SuperAgent\Harness\Wire\WireEvent;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;

/**
 * Pins the harness glue: observations go through the detector, the
 * inner handler keeps getting every event unchanged, and the
 * `onViolation` callback fires exactly once per prompt when a
 * detector trips.
 */
class LoopDetectionHarnessTest extends TestCase
{
    public function test_tool_loop_fires_onViolation_exactly_once(): void
    {
        $detector = new LoopDetector();
        $violations = [];

        $wrapped = LoopDetectionHarness::wrap(
            inner: null,
            detector: $detector,
            onViolation: static function (LoopViolation $v) use (&$violations): void {
                $violations[] = $v;
            },
        );

        // Lift cold-start with a non-read tool, then trip TOOL_LOOP.
        $wrapped->emitToolUse(ContentBlock::toolUse('b1', 'Bash', ['command' => 'ls']));
        for ($i = 0; $i < 5; $i++) {
            $wrapped->emitToolUse(ContentBlock::toolUse('e' . $i, 'Edit', ['file' => '/a']));
        }

        $this->assertCount(1, $violations, 'onViolation fires exactly once');
        $this->assertSame(LoopType::ToolLoop, $violations[0]->type);

        // Further emissions after violation — observer still runs on
        // the inner handler, but onViolation does NOT fire again.
        $wrapped->emitToolUse(ContentBlock::toolUse('e99', 'Edit', ['file' => '/a']));
        $this->assertCount(1, $violations);
    }

    public function test_inner_handler_sees_every_event_unchanged(): void
    {
        $detector = new LoopDetector();
        $captured = ['text' => [], 'tool' => [], 'thinking' => []];
        $inner = new StreamingHandler(
            onText: function (string $delta, string $full) use (&$captured): void {
                $captured['text'][] = ['delta' => $delta, 'full' => $full];
            },
            onThinking: function (string $delta, string $full) use (&$captured): void {
                $captured['thinking'][] = $delta;
            },
            onToolUse: function (ContentBlock $block) use (&$captured): void {
                $captured['tool'][] = $block->toolName;
            },
        );

        $wrapped = LoopDetectionHarness::wrap($inner, $detector);

        $wrapped->emitText('Hello ',   'Hello ');
        $wrapped->emitText('world',    'Hello world');
        $wrapped->emitThinking('plan', 'plan');
        $wrapped->emitToolUse(ContentBlock::toolUse('t', 'Read', ['path' => '/a']));

        $this->assertSame([
            ['delta' => 'Hello ', 'full' => 'Hello '],
            ['delta' => 'world',  'full' => 'Hello world'],
        ], $captured['text']);
        $this->assertSame(['plan'], $captured['thinking']);
        $this->assertSame(['Read'], $captured['tool']);
    }

    public function test_no_violation_callback_means_silent_observation(): void
    {
        // Wrap with null onViolation — detector still runs, but nothing
        // external fires. Lets callers opt into detection without
        // wiring up event handling (useful for tests / smoke runs).
        $detector = new LoopDetector();
        $wrapped = LoopDetectionHarness::wrap(null, $detector, onViolation: null);

        $wrapped->emitToolUse(ContentBlock::toolUse('b1', 'Bash', ['command' => 'ls']));
        for ($i = 0; $i < 5; $i++) {
            $wrapped->emitToolUse(ContentBlock::toolUse('e' . $i, 'Edit', ['file' => '/a']));
        }

        $this->assertNotNull($detector->lastViolation(), 'detector still registers the loop');
    }

    public function test_loop_detected_event_is_wire_compliant(): void
    {
        $v = new LoopViolation(
            LoopType::Stagnation,
            "Tool 'Grep' called 8 times in a row (varying args) — parameter thrashing",
            ['tool' => 'Grep', 'count' => 8],
        );
        $event = LoopDetectedEvent::fromViolation($v);
        $this->assertInstanceOf(WireEvent::class, $event);

        $arr = $event->toArray();
        $this->assertSame(1, $arr['wire_version']);
        $this->assertSame('loop_detected', $arr['type']);
        $this->assertSame('stagnation', $arr['loop_type']);
        $this->assertStringContainsString('Grep', $arr['message']);
        $this->assertSame(['tool' => 'Grep', 'count' => 8], $arr['metadata']);
    }

    public function test_thinking_channel_feeds_thought_detector(): void
    {
        $detector = new LoopDetector();
        $fired = false;
        $capturedType = null;
        $wrapped = LoopDetectionHarness::wrap(
            inner: null,
            detector: $detector,
            onViolation: function (LoopViolation $v) use (&$fired, &$capturedType): void {
                $fired = true;
                $capturedType = $v->type;
            },
        );

        $wrapped->emitThinking('I should analyze...', 'I should analyze...');
        $wrapped->emitThinking('I should analyze...', 'x');
        $wrapped->emitThinking('I should analyze...', 'x');  // 3rd — trips
        $this->assertTrue($fired);
        $this->assertSame(LoopType::ThoughtLoop, $capturedType);
    }

    public function test_content_channel_feeds_content_detector(): void
    {
        $detector = new LoopDetector();
        $fired = false;
        $capturedType = null;
        $wrapped = LoopDetectionHarness::wrap(
            inner: null,
            detector: $detector,
            onViolation: function (LoopViolation $v) use (&$fired, &$capturedType): void {
                $fired = true;
                $capturedType = $v->type;
            },
        );

        $phrase = str_repeat('A', 50);
        for ($i = 0; $i < 12; $i++) {
            $wrapped->emitText($phrase, str_repeat($phrase, $i + 1));
        }
        $this->assertTrue($fired);
        $this->assertSame(LoopType::ContentLoop, $capturedType);
    }
}
