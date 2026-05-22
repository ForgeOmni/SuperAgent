<?php

namespace SuperAgent\Tests\Tracing;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tracing\PiEventStream;
use SuperAgent\Tracing\PiEventStreamWriter;

class PiEventStreamTest extends TestCase
{
    protected function tearDown(): void
    {
        PiEventStream::reset();
    }

    public function test_emit_dispatches_to_listeners_with_timestamp(): void
    {
        $captured = [];
        PiEventStream::subscribe(function (array $event) use (&$captured) {
            $captured[] = $event;
        });

        PiEventStream::emit(PiEventStream::TURN_START, [
            'sessionId' => 's-1',
            'turnId' => 't-1',
        ]);

        $this->assertCount(1, $captured);
        $this->assertSame('turn_start', $captured[0]['type']);
        $this->assertSame('s-1', $captured[0]['sessionId']);
        $this->assertNotEmpty($captured[0]['timestamp']);
    }

    public function test_writer_emits_one_json_per_line(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pi_stream_' . uniqid() . '.jsonl';
        $writer = new PiEventStreamWriter($tmp);
        PiEventStream::subscribe($writer);

        PiEventStream::emit(PiEventStream::AGENT_START, ['sessionId' => 's']);
        PiEventStream::emit(PiEventStream::TURN_START, ['turnId' => 't']);
        PiEventStream::emit(PiEventStream::TURN_END, ['turnId' => 't']);
        PiEventStream::emit(PiEventStream::AGENT_END, ['sessionId' => 's']);
        $writer->close();

        $lines = file($tmp, FILE_IGNORE_NEW_LINES);
        $this->assertCount(4, $lines);
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('type', $decoded);
            $this->assertNotFalse(strpos($line, "\n") === false ? true : false); // LF only after writing
        }
        unlink($tmp);
    }

    public function test_legacy_alias_translation(): void
    {
        $this->assertSame('tool_execution_end', PiEventStream::translateLegacy('tool_execution'));
        $this->assertSame('turn_start', PiEventStream::translateLegacy('llm_request'));
        $this->assertNull(PiEventStream::translateLegacy('not_a_thing'));
    }
}
