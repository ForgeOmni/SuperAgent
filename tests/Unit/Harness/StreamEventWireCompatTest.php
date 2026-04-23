<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Harness;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\Wire\JsonStreamRenderer;
use SuperAgent\Harness\Wire\WireEvent;
use SuperAgent\Harness\ToolStartedEvent;
use SuperAgent\Harness\ToolCompletedEvent;
use SuperAgent\Harness\TextDeltaEvent;
use SuperAgent\Harness\ErrorEvent;

/**
 * Phase 8b migration acceptance tests.
 *
 * When `StreamEvent` started implementing `WireEvent`, every concrete
 * subclass inherited compliance for free. This suite proves that: we
 * instantiate a sample of the shipped classes and verify each one
 *
 *   1. `instanceof WireEvent`
 *   2. `toArray()` carries `wire_version: 1` and a stable `type`
 *   3. `JsonStreamRenderer::format()` produces a valid one-line NDJSON
 *      record that consumers can parse with a single `json_decode`.
 *
 * The classes we sample span four of the five v1 event families
 * documented in `docs/WIRE_PROTOCOL.md`: tool, text, and error.
 * Adding a new StreamEvent subclass in the future requires zero test
 * changes here — if it compiles, it's already wire-compliant.
 */
class StreamEventWireCompatTest extends TestCase
{
    public function test_tool_started_is_wire_compliant(): void
    {
        $e = new ToolStartedEvent('Read', 'toolu_abc', ['file_path' => '/tmp/x']);
        $this->assertInstanceOf(WireEvent::class, $e);
        $arr = $e->toArray();
        $this->assertSame(1, $arr['wire_version']);
        $this->assertSame('tool_started', $arr['type']);
        $this->assertSame('tool_started', $e->eventType());
        $this->assertSame(1, $e->wireVersion());
        $this->assertSame('Read', $arr['tool_name']);
    }

    public function test_tool_completed_is_wire_compliant(): void
    {
        $e = new ToolCompletedEvent('Read', 'toolu_abc', 'ok', false);
        $arr = $e->toArray();
        $this->assertSame(1, $arr['wire_version']);
        $this->assertSame('tool_completed', $arr['type']);
        $this->assertSame(2, $arr['output_length']);
        $this->assertFalse($arr['is_error']);
    }

    public function test_text_delta_is_wire_compliant(): void
    {
        $e = new TextDeltaEvent('hello');
        $arr = $e->toArray();
        $this->assertSame(1, $arr['wire_version']);
        $this->assertSame('text_delta', $arr['type']);
    }

    public function test_error_event_is_wire_compliant(): void
    {
        $e = new ErrorEvent('Something broke', false, 'E001');
        $arr = $e->toArray();
        $this->assertSame(1, $arr['wire_version']);
        $this->assertSame('error', $arr['type']);
        $this->assertSame('Something broke', $arr['message']);
    }

    public function test_json_stream_renderer_roundtrips_any_stream_event(): void
    {
        $events = [
            new ToolStartedEvent('Read', 'id-1', []),
            new TextDeltaEvent('chunk'),
            new ToolCompletedEvent('Read', 'id-1', 'done', false),
            new ErrorEvent('fail', true, null),
        ];
        $buf = '';
        foreach ($events as $e) {
            $buf .= JsonStreamRenderer::format($e);
        }
        $lines = array_values(array_filter(explode("\n", $buf)));
        $this->assertCount(4, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertSame(1, $decoded['wire_version']);
            $this->assertArrayHasKey('type', $decoded);
            $this->assertArrayHasKey('timestamp', $decoded);
        }
    }

    public function test_pre_0_8_9_fields_still_present_on_toArray(): void
    {
        // Compat guardrail — the pre-migration shape shipped `type` and
        // `timestamp` on every event. Adding `wire_version` is
        // additive; removing or renaming the old keys would break
        // pre-0.8.9 consumers.
        $e = new ToolStartedEvent('Bash', 'id', []);
        $arr = $e->toArray();
        $this->assertArrayHasKey('type', $arr);
        $this->assertArrayHasKey('timestamp', $arr);
        $this->assertIsFloat($arr['timestamp']);
    }
}
