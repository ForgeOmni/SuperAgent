<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\AgentFactory;
use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Harness\ToolStartedEvent;

/**
 * Pins the wiring between `AgentFactory::makeJsonStreamEmitter()`
 * and `WireStreamOutput`. Without this the `--output json-stream`
 * CLI flag does nothing — events never reach the stream.
 *
 * End-to-end argv → options parsing is covered separately; this
 * focuses on the factory seam so we can exercise it without
 * spinning up a full Agent.
 */
class JsonStreamOutputFactoryTest extends TestCase
{
    public function test_emitter_writes_ndjson_wire_events_to_given_stream(): void
    {
        $fh = fopen('php://memory', 'r+');
        $factory = new AgentFactory(new Renderer());

        $emitter = $factory->makeJsonStreamEmitter($fh);
        $emitter->emit(new ToolStartedEvent('Read', 'toolu_1', ['file_path' => '/tmp/x']));
        $emitter->emit(new ToolStartedEvent('Grep', 'toolu_2', ['pattern' => 'foo']));

        rewind($fh);
        $contents = stream_get_contents($fh);
        fclose($fh);

        $lines = array_values(array_filter(explode("\n", $contents)));
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'each line must be valid JSON');
            $this->assertSame(1, $decoded['wire_version']);
            $this->assertSame('tool_started', $decoded['type']);
        }
        $this->assertSame('Read', json_decode($lines[0], true)['tool_name']);
        $this->assertSame('Grep', json_decode($lines[1], true)['tool_name']);
    }

    public function test_every_stream_event_becomes_a_wire_line(): void
    {
        // Post Phase-8b all StreamEvent subclasses implement
        // WireEvent, so every legitimate emitted event lands on the
        // json-stream. Sample three diverse events as a regression
        // guard against a future event class that forgets the
        // inheritance chain.
        $fh = fopen('php://memory', 'r+');
        $factory = new AgentFactory(new Renderer());
        $emitter = $factory->makeJsonStreamEmitter($fh);

        $emitter->emit(new ToolStartedEvent('Read', 'id-1', []));
        $emitter->emit(new \SuperAgent\Harness\TextDeltaEvent('chunk'));
        $emitter->emit(new \SuperAgent\Harness\ErrorEvent('oops', true, null));

        rewind($fh);
        $read = stream_get_contents($fh);
        fclose($fh);

        $lines = array_values(array_filter(explode("\n", $read)));
        $this->assertCount(3, $lines);
        $types = array_map(static fn ($l) => json_decode($l, true)['type'], $lines);
        $this->assertSame(['tool_started', 'text_delta', 'error'], $types);
    }
}
