<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\MCP;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\Manifest;
use SuperAgent\MCP\McpJsonWriter;

class McpJsonWriterTest extends TestCase
{
    private string $root;
    private string $mcpJson;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mcp-writer-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->mcpJson = $this->root . '/.mcp.json';
        $this->manifestPath = $this->root . '/.superagent/mcp-manifest.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->mcpJson);
        @unlink($this->manifestPath);
        @rmdir($this->root . '/.superagent');
        @rmdir($this->root);
    }

    private function writer(): McpJsonWriter
    {
        return new McpJsonWriter($this->mcpJson, new Manifest($this->manifestPath));
    }

    private function servers(): array
    {
        return [
            'sqlite' => ['type' => 'stdio', 'command' => 'uvx', 'args' => ['mcp-server-sqlite'], 'env' => []],
            'brave'  => ['type' => 'stdio', 'command' => 'npx', 'args' => ['@brave/mcp'], 'env' => ['BRAVE_API_KEY' => 'k']],
        ];
    }

    // ------------------------------------------------------------------
    // Render shape
    // ------------------------------------------------------------------

    public function test_render_omits_empty_args_and_env(): void
    {
        $out = McpJsonWriter::render([
            'x' => ['type' => 'stdio', 'command' => 'cmd', 'args' => [], 'env' => []],
        ]);
        $decoded = json_decode($out, true);
        $this->assertSame(['x' => ['type' => 'stdio', 'command' => 'cmd']], $decoded['mcpServers']);
    }

    public function test_render_trailing_newline(): void
    {
        $out = McpJsonWriter::render(['x' => ['type' => 'stdio', 'command' => 'y', 'args' => [], 'env' => []]]);
        $this->assertStringEndsWith("\n", $out);
    }

    // ------------------------------------------------------------------
    // Sync lifecycle
    // ------------------------------------------------------------------

    public function test_first_sync_writes_and_records_manifest(): void
    {
        $result = $this->writer()->sync($this->servers());
        $this->assertSame(McpJsonWriter::STATUS_WRITTEN, $result['status']);
        $this->assertFileExists($this->mcpJson);

        $manifest = (new Manifest($this->manifestPath))->read();
        $this->assertArrayHasKey($this->mcpJson, $manifest);
        $this->assertSame(64, strlen($manifest[$this->mcpJson]), 'sha256 hex is 64 chars');
    }

    public function test_second_sync_reports_unchanged(): void
    {
        $this->writer()->sync($this->servers());
        $result = $this->writer()->sync($this->servers());
        $this->assertSame(McpJsonWriter::STATUS_UNCHANGED, $result['status']);
    }

    public function test_source_change_reflects_as_written_again(): void
    {
        $this->writer()->sync($this->servers());
        $newServers = $this->servers();
        $newServers['sqlite']['args'] = ['mcp-server-sqlite', '--db=prod.db'];

        $result = $this->writer()->sync($newServers);
        $this->assertSame(McpJsonWriter::STATUS_WRITTEN, $result['status']);

        $onDisk = json_decode((string) file_get_contents($this->mcpJson), true);
        $this->assertContains('--db=prod.db', $onDisk['mcpServers']['sqlite']['args']);
    }

    public function test_user_edit_preserved(): void
    {
        $this->writer()->sync($this->servers());
        // Simulate a user edit — append a dummy key post-hoc.
        $current = (string) file_get_contents($this->mcpJson);
        file_put_contents($this->mcpJson, rtrim($current) . "\n// user comment\n");

        $result = $this->writer()->sync($this->servers());
        $this->assertSame(McpJsonWriter::STATUS_USER_EDITED, $result['status']);

        $this->assertStringContainsString('user comment', (string) file_get_contents($this->mcpJson));
    }

    public function test_dry_run_does_not_touch_disk(): void
    {
        $result = $this->writer()->sync($this->servers(), dryRun: true);
        $this->assertSame(McpJsonWriter::STATUS_WRITTEN, $result['status']);
        $this->assertFileDoesNotExist($this->mcpJson, 'dry-run must not write');
        $this->assertFileDoesNotExist($this->manifestPath, 'dry-run must not write manifest');
    }

    public function test_user_deleted_file_recreated(): void
    {
        // First sync writes + manifest.
        $this->writer()->sync($this->servers());
        $this->assertFileExists($this->mcpJson);

        // User deletes the file; manifest still says we own it.
        unlink($this->mcpJson);

        // Next sync sees it's missing and recreates — treating
        // "missing" as "ours to create" is the right conservative
        // behaviour for an opt-in writer.
        $result = $this->writer()->sync($this->servers());
        $this->assertSame(McpJsonWriter::STATUS_WRITTEN, $result['status']);
        $this->assertFileExists($this->mcpJson);
    }
}
