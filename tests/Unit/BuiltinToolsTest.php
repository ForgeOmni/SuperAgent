<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\Builtin\BashTool;
use SuperAgent\Tools\Builtin\HttpRequestTool;
use SuperAgent\Tools\Builtin\ReadFileTool;
use SuperAgent\Tools\Builtin\WriteFileTool;

class BuiltinToolsTest extends TestCase
{
    // --- BashTool ---

    public function test_bash_echo(): void
    {
        $tool = new BashTool();
        $result = $tool->execute(['command' => 'echo "hello world"']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('hello world', $result->contentAsString());
    }

    public function test_bash_exit_code(): void
    {
        $tool = new BashTool();
        $result = $tool->execute(['command' => 'exit 1']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Exit code 1', $result->contentAsString());
    }

    public function test_bash_empty_command(): void
    {
        $tool = new BashTool();
        $result = $tool->execute(['command' => '']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('empty', $result->contentAsString());
    }

    public function test_bash_stderr(): void
    {
        $tool = new BashTool();
        $result = $tool->execute(['command' => 'echo "err" >&2 && exit 1']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('err', $result->contentAsString());
    }

    public function test_bash_timeout(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Use a Windows-compatible long-running command
            $tool = new BashTool(timeout: 1);
            $result = $tool->execute(['command' => 'ping -n 20 127.0.0.1']);
        } else {
            $tool = new BashTool(timeout: 1);
            $result = $tool->execute(['command' => 'sleep 10']);
        }

        $this->assertTrue($result->isError);
        $this->assertTrue(
            str_contains($result->contentAsString(), 'timed out')
            || str_contains($result->contentAsString(), 'Exit code'),
            "Expected timeout or error in output: {$result->contentAsString()}"
        );
    }

    public function test_bash_working_directory(): void
    {
        $tmpDir = sys_get_temp_dir();
        $tool = new BashTool(workingDirectory: $tmpDir);

        if (PHP_OS_FAMILY === 'Windows') {
            $result = $tool->execute(['command' => 'cd']);
        } else {
            $result = $tool->execute(['command' => 'pwd']);
        }

        $this->assertFalse($result->isError, "Unexpected error: {$result->contentAsString()}");
        // Normalize paths for comparison
        $output = str_replace('\\', '/', strtolower($result->contentAsString()));
        $expected = str_replace('\\', '/', strtolower($tmpDir));
        $this->assertTrue(
            str_contains($output, $expected) || str_contains($output, '/tmp'),
            "Expected temp dir in output: {$result->contentAsString()}"
        );
    }

    public function test_bash_definition(): void
    {
        $tool = new BashTool();

        $this->assertSame('bash', $tool->name());
        $this->assertFalse($tool->isReadOnly());
        $this->assertArrayHasKey('properties', $tool->inputSchema());
    }

    // --- ReadFileTool ---

    public function test_read_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sa_test_');
        file_put_contents($tmpFile, "line1\nline2\nline3\n");

        $tool = new ReadFileTool();
        $result = $tool->execute(['path' => $tmpFile]);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('line1', $result->contentAsString());
        $this->assertStringContainsString('line2', $result->contentAsString());
        $this->assertTrue($tool->isReadOnly());

        unlink($tmpFile);
    }

    public function test_read_file_with_offset_and_limit(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sa_test_');
        file_put_contents($tmpFile, "a\nb\nc\nd\ne\n");

        $tool = new ReadFileTool();
        $result = $tool->execute(['path' => $tmpFile, 'offset' => 1, 'limit' => 2]);

        $output = $result->contentAsString();
        $this->assertStringContainsString('b', $output);
        $this->assertStringContainsString('c', $output);
        $this->assertStringNotContainsString('1	a', $output); // line 1 skipped

        unlink($tmpFile);
    }

    public function test_read_file_not_found(): void
    {
        $tool = new ReadFileTool();
        $result = $tool->execute(['path' => '/nonexistent_file_xyz']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->contentAsString());
    }

    public function test_read_file_empty_path(): void
    {
        $tool = new ReadFileTool();
        $result = $tool->execute(['path' => '']);

        $this->assertTrue($result->isError);
    }

    public function test_read_file_directory(): void
    {
        $tool = new ReadFileTool();
        $result = $tool->execute(['path' => '/tmp']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('directory', $result->contentAsString());
    }

    // --- WriteFileTool ---

    public function test_write_file(): void
    {
        $tmpFile = sys_get_temp_dir() . '/sa_test_write_' . uniqid();

        $tool = new WriteFileTool();
        $result = $tool->execute(['path' => $tmpFile, 'content' => "hello\nworld"]);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Wrote', $result->contentAsString());
        $this->assertSame("hello\nworld", file_get_contents($tmpFile));

        unlink($tmpFile);
    }

    public function test_write_file_creates_directories(): void
    {
        $dir = sys_get_temp_dir() . '/sa_test_dir_' . uniqid();
        $path = $dir . '/sub/file.txt';

        $tool = new WriteFileTool();
        $result = $tool->execute(['path' => $path, 'content' => 'test']);

        $this->assertFalse($result->isError);
        $this->assertFileExists($path);
        $this->assertSame('test', file_get_contents($path));

        // Cleanup
        unlink($path);
        rmdir($dir . '/sub');
        rmdir($dir);
    }

    public function test_write_file_empty_path(): void
    {
        $tool = new WriteFileTool();
        $result = $tool->execute(['path' => '', 'content' => 'x']);

        $this->assertTrue($result->isError);
    }

    public function test_write_file_definition(): void
    {
        $tool = new WriteFileTool();

        $this->assertSame('write_file', $tool->name());
        $this->assertFalse($tool->isReadOnly());
    }

    // --- HttpRequestTool ---

    public function test_http_tool_definition(): void
    {
        $tool = new HttpRequestTool();

        $this->assertSame('http_request', $tool->name());
        $this->assertTrue($tool->isReadOnly());
        $schema = $tool->inputSchema();
        $this->assertArrayHasKey('url', $schema['properties']);
    }

    public function test_http_empty_url(): void
    {
        $tool = new HttpRequestTool();
        $result = $tool->execute(['url' => '']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('empty', $result->contentAsString());
    }

    public function test_http_invalid_url(): void
    {
        $tool = new HttpRequestTool();
        $result = $tool->execute(['url' => 'http://localhost:1']); // port 1, should fail

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('failed', $result->contentAsString());
    }
}
