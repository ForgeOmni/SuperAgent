<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Harness;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\ToolStartedEvent;
use SuperAgent\Harness\Wire\WireStreamOutput;

class WireStreamOutputTest extends TestCase
{
    public function test_emit_writes_ndjson_line_and_returns_bytecount(): void
    {
        $fh = fopen('php://memory', 'r+');
        $out = new WireStreamOutput($fh, flushAfterWrite: false);

        $bytes = $out->emit(new ToolStartedEvent('Read', 'id-1', []));
        $this->assertGreaterThan(0, $bytes);

        rewind($fh);
        $contents = stream_get_contents($fh);
        fclose($fh);
        $this->assertStringEndsWith("\n", $contents);
        $decoded = json_decode(rtrim($contents), true);
        $this->assertSame(1, $decoded['wire_version']);
        $this->assertSame('tool_started', $decoded['type']);
    }

    public function test_emit_all_writes_every_event(): void
    {
        $fh = fopen('php://memory', 'r+');
        $out = new WireStreamOutput($fh, flushAfterWrite: false);

        $out->emitAll([
            new ToolStartedEvent('Read', 'id-1', []),
            new ToolStartedEvent('Grep', 'id-2', []),
            new ToolStartedEvent('Write', 'id-3', []),
        ]);

        rewind($fh);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($fh))));
        fclose($fh);
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertIsArray(json_decode($line, true));
        }
    }

    public function test_non_resource_stream_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/writable resource/');
        /** @phpstan-ignore-next-line — deliberately wrong type */
        new WireStreamOutput('not-a-resource');
    }

    public function test_flush_after_write_defaults_true(): void
    {
        // Can't observe fflush directly from userland, but we can
        // pin that the default constructor doesn't throw and emits
        // work — the flush flag is exercised implicitly by the
        // memory-stream write succeeding.
        $fh = fopen('php://memory', 'r+');
        $out = new WireStreamOutput($fh);
        $bytes = $out->emit(new ToolStartedEvent('Read', 'id-x', []));
        fclose($fh);
        $this->assertGreaterThan(0, $bytes);
    }
}
