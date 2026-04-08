<?php

namespace SuperAgent\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use SuperAgent\Output\SafeStreamWriter;

class SafeStreamWriterTest extends TestCase
{
    public function test_write_to_stream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $writer = new SafeStreamWriter($stream);

        $written = $writer->write('hello');
        $this->assertEquals(5, $written);
        $this->assertTrue($writer->isWritable());

        rewind($stream);
        $this->assertEquals('hello', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_writeln_appends_newline(): void
    {
        $stream = fopen('php://memory', 'w+');
        $writer = new SafeStreamWriter($stream);

        $writer->writeln('line');

        rewind($stream);
        $this->assertEquals('line' . PHP_EOL, stream_get_contents($stream));
        fclose($stream);
    }

    public function test_write_to_closed_stream_returns_false(): void
    {
        // After fclose, the variable is no longer a resource
        // SafeStreamWriter treats non-resource as null → returns false
        $stream = fopen('php://memory', 'w+');
        fclose($stream);

        $writer = new SafeStreamWriter($stream);
        $result = $writer->write('data');

        // Should not throw, returns false (stream is null/non-resource)
        $this->assertFalse($result);
        $this->assertFalse($writer->isWritable());
    }

    public function test_null_stream_returns_false(): void
    {
        $writer = new SafeStreamWriter(null);
        $this->assertFalse($writer->write('data'));
        $this->assertFalse($writer->isWritable());
    }

    public function test_subsequent_writes_after_null_stream_return_false(): void
    {
        // null stream always returns false
        $writer = new SafeStreamWriter(null);
        $this->assertFalse($writer->write('first'));
        $this->assertFalse($writer->write('second'));
    }

    public function test_flush_on_writable_stream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $writer = new SafeStreamWriter($stream);

        $writer->write('data');
        $this->assertTrue($writer->flush());
        fclose($stream);
    }

    public function test_flush_on_null_stream(): void
    {
        $writer = new SafeStreamWriter(null);
        $this->assertFalse($writer->flush());
    }

    public function test_static_stdout_factory(): void
    {
        $writer = SafeStreamWriter::stdout();
        $this->assertInstanceOf(SafeStreamWriter::class, $writer);
    }

    public function test_static_stderr_factory(): void
    {
        $writer = SafeStreamWriter::stderr();
        $this->assertInstanceOf(SafeStreamWriter::class, $writer);
    }
}
