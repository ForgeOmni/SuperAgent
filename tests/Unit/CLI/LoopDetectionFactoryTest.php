<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\AgentFactory;
use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Guardrails\LoopType;
use SuperAgent\Harness\ToolStartedEvent;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;

/**
 * Pins the opt-in contract for loop detection via the CLI factory.
 * Without the option set the factory must return the inner handler
 * unchanged (zero behaviour change for existing callers); with it
 * set, tool-call chunks get observed and violations land as
 * `loop_detected` wire events on the provided emitter.
 */
class LoopDetectionFactoryTest extends TestCase
{
    public function test_no_option_returns_inner_handler_unchanged(): void
    {
        $factory = new AgentFactory(new Renderer());
        $inner = new StreamingHandler();
        [$handler, $detector] = $factory->maybeWrapWithLoopDetection($inner, []);

        $this->assertSame($inner, $handler);
        $this->assertNull($detector);
    }

    public function test_false_option_returns_inner_handler_unchanged(): void
    {
        $factory = new AgentFactory(new Renderer());
        $inner = new StreamingHandler();
        [$handler, $detector] = $factory->maybeWrapWithLoopDetection(
            $inner,
            ['loop_detection' => false],
        );
        $this->assertSame($inner, $handler);
        $this->assertNull($detector);
    }

    public function test_truthy_option_returns_wrapped_handler_and_detector(): void
    {
        $factory = new AgentFactory(new Renderer());
        [$handler, $detector] = $factory->maybeWrapWithLoopDetection(
            null,
            ['loop_detection' => true],
        );
        $this->assertNotNull($handler);
        $this->assertInstanceOf(StreamingHandler::class, $handler);
        $this->assertNotNull($detector);
    }

    public function test_array_option_applies_threshold_overrides(): void
    {
        $factory = new AgentFactory(new Renderer());
        $emitter = $factory->makeJsonStreamEmitter(fopen('php://memory', 'r+'));

        [$handler, $detector] = $factory->maybeWrapWithLoopDetection(
            null,
            ['loop_detection' => ['TOOL_CALL_LOOP_THRESHOLD' => 2]],
            $emitter,
        );

        // Under override=2, two identical tool calls trip TOOL_LOOP
        // (the default would have been 5).
        $handler->emitToolUse(ContentBlock::toolUse('b', 'Bash', ['command' => 'ls']));
        $handler->emitToolUse(ContentBlock::toolUse('e1', 'Edit', ['file' => '/a']));
        $handler->emitToolUse(ContentBlock::toolUse('e2', 'Edit', ['file' => '/a']));

        $this->assertNotNull($detector->lastViolation());
        $this->assertSame(LoopType::ToolLoop, $detector->lastViolation()->type);
    }

    public function test_violation_emits_loop_detected_wire_event(): void
    {
        $fh = fopen('php://memory', 'r+');
        $factory = new AgentFactory(new Renderer());
        $emitter = $factory->makeJsonStreamEmitter($fh);

        [$handler, $detector] = $factory->maybeWrapWithLoopDetection(
            null,
            ['loop_detection' => ['TOOL_CALL_LOOP_THRESHOLD' => 2]],
            $emitter,
        );

        // Also emit a non-loop event before tripping so we can count
        // the total lines on the stream.
        $emitter->emit(new ToolStartedEvent('Read', 'id-x', []));

        $handler->emitToolUse(ContentBlock::toolUse('b', 'Bash', ['command' => 'ls']));
        $handler->emitToolUse(ContentBlock::toolUse('e1', 'Edit', ['file' => '/a']));
        $handler->emitToolUse(ContentBlock::toolUse('e2', 'Edit', ['file' => '/a']));

        rewind($fh);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($fh))));
        fclose($fh);

        $loopLines = array_values(array_filter(
            $lines,
            static fn ($l) => (json_decode($l, true)['type'] ?? null) === 'loop_detected',
        ));
        $this->assertCount(1, $loopLines);
        $decoded = json_decode($loopLines[0], true);
        $this->assertSame(1, $decoded['wire_version']);
        $this->assertSame('tool_loop', $decoded['loop_type']);
    }
}
