<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Harness;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\Wire\JsonStreamRenderer;
use SuperAgent\Harness\Wire\WireEvent;

class WireEventTest extends TestCase
{
    public function test_renderer_emits_single_json_line_terminated_with_newline(): void
    {
        $event = new FakeTurnBeginEvent(3, 'claude-opus-4-7', 'anthropic');
        $out = JsonStreamRenderer::format($event);

        $this->assertStringEndsWith("\n", $out);
        $this->assertSame(1, substr_count($out, "\n"));
        $decoded = json_decode(rtrim($out), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['wire_version']);
        $this->assertSame('turn.begin', $decoded['type']);
        $this->assertSame(3, $decoded['turn']);
        $this->assertSame('claude-opus-4-7', $decoded['model']);
    }

    public function test_renderer_injects_type_and_version_when_implementation_forgets(): void
    {
        // Guardrail: the WireEvent contract says toArray() carries
        // `type` + `wire_version`, but defensively the renderer also
        // patches them in so the stream stays self-describing.
        $event = new MisbehavingEvent();
        $out = JsonStreamRenderer::format($event);
        $decoded = json_decode(rtrim($out), true);

        $this->assertSame(1, $decoded['wire_version']);
        $this->assertSame('misbehaving', $decoded['type']);
    }

    public function test_emit_writes_to_stream(): void
    {
        $fh = fopen('php://memory', 'r+');
        $event = new FakeTurnBeginEvent(1, 'm', 'p');
        $bytes = JsonStreamRenderer::emit($event, $fh);
        $this->assertGreaterThan(0, $bytes);

        rewind($fh);
        $read = stream_get_contents($fh);
        fclose($fh);
        $this->assertStringEndsWith("\n", $read);
        $decoded = json_decode(rtrim($read), true);
        $this->assertSame('turn.begin', $decoded['type']);
    }

    public function test_emit_is_line_framed_for_multiple_events(): void
    {
        $fh = fopen('php://memory', 'r+');
        JsonStreamRenderer::emit(new FakeTurnBeginEvent(1, 'm', 'p'), $fh);
        JsonStreamRenderer::emit(new FakeTurnBeginEvent(2, 'm', 'p'), $fh);
        JsonStreamRenderer::emit(new FakeTurnBeginEvent(3, 'm', 'p'), $fh);
        rewind($fh);
        $read = (string) stream_get_contents($fh);
        fclose($fh);

        $lines = array_values(array_filter(explode("\n", $read)));
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'every line must be valid JSON');
            $this->assertSame(1, $decoded['wire_version']);
        }
    }
}

// ── test doubles ───────────────────────────────────────────────

final class FakeTurnBeginEvent implements WireEvent
{
    public function __construct(
        private int $turn,
        private string $model,
        private string $provider,
    ) {
    }
    public function wireVersion(): int { return 1; }
    public function eventType(): string { return 'turn.begin'; }
    public function toArray(): array
    {
        return [
            'wire_version' => 1,
            'type' => 'turn.begin',
            'turn' => $this->turn,
            'model' => $this->model,
            'provider' => $this->provider,
        ];
    }
}

final class MisbehavingEvent implements WireEvent
{
    public function wireVersion(): int { return 1; }
    public function eventType(): string { return 'misbehaving'; }
    public function toArray(): array { return ['payload' => 'x']; }  // missing wire_version + type
}
