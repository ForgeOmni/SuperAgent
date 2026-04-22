<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\MCP;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\MCPManager;

class UserConfigTest extends TestCase
{
    private string $tmpHome;
    private string $tmpPath;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/superagent_mcp_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpHome . '/.superagent', 0755, true);
        $this->tmpPath = $this->tmpHome . '/.superagent/mcp.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpPath)) {
            @unlink($this->tmpPath);
        }
        if (is_dir($this->tmpHome . '/.superagent')) {
            @rmdir($this->tmpHome . '/.superagent');
        }
        if (is_dir($this->tmpHome)) {
            @rmdir($this->tmpHome);
        }
    }

    public function test_read_user_config_returns_empty_when_missing(): void
    {
        $this->assertSame([], MCPManager::readUserConfig($this->tmpPath));
    }

    public function test_write_then_read_round_trip(): void
    {
        $servers = [
            'filesystem' => [
                'type' => 'stdio',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/tmp'],
            ],
            'search' => [
                'type' => 'http',
                'url' => 'https://mcp.example.com/search',
                'headers' => ['Authorization' => 'Bearer token'],
            ],
        ];

        MCPManager::writeUserConfig($servers, $this->tmpPath);

        $this->assertFileExists($this->tmpPath);
        $restored = MCPManager::readUserConfig($this->tmpPath);
        $this->assertSame($servers, $restored);
    }

    public function test_read_accepts_claude_code_mcp_servers_envelope(): void
    {
        file_put_contents($this->tmpPath, json_encode([
            'mcpServers' => [
                'cc-server' => ['type' => 'stdio', 'command' => 'server-bin'],
            ],
        ]));

        $servers = MCPManager::readUserConfig($this->tmpPath);
        $this->assertArrayHasKey('cc-server', $servers);
        $this->assertSame('server-bin', $servers['cc-server']['command']);
    }

    public function test_read_handles_malformed_json(): void
    {
        file_put_contents($this->tmpPath, '{ not valid json');
        $this->assertSame([], MCPManager::readUserConfig($this->tmpPath));
    }

    public function test_write_is_atomic_via_temp_rename(): void
    {
        // Arrange: pre-existing (good) config.
        MCPManager::writeUserConfig(['a' => ['type' => 'stdio', 'command' => 'a']], $this->tmpPath);

        // Act: overwrite.
        MCPManager::writeUserConfig(['b' => ['type' => 'stdio', 'command' => 'b']], $this->tmpPath);

        // Assert: final file has only the new server — no leftover tmp.
        $final = MCPManager::readUserConfig($this->tmpPath);
        $this->assertArrayHasKey('b', $final);
        $this->assertArrayNotHasKey('a', $final);
        $this->assertFileDoesNotExist($this->tmpPath . '.tmp');
    }

    public function test_write_creates_missing_directory(): void
    {
        $nested = $this->tmpHome . '/deep/path/mcp.json';
        MCPManager::writeUserConfig(['x' => ['type' => 'stdio', 'command' => 'x']], $nested);
        $this->assertFileExists($nested);
        @unlink($nested);
        @rmdir(dirname($nested));
        @rmdir(dirname($nested, 2));
    }

    public function test_user_config_path_honours_home_env(): void
    {
        $originalHome = getenv('HOME');
        $originalProfile = getenv('USERPROFILE');
        try {
            putenv('HOME=' . $this->tmpHome);
            putenv('USERPROFILE=');  // Windows — blank it so HOME wins
            $path = MCPManager::userConfigPath();
            $this->assertStringContainsString('.superagent/mcp.json', str_replace('\\', '/', $path));
            $this->assertStringStartsWith($this->tmpHome, $path);
        } finally {
            putenv('HOME' . ($originalHome === false ? '' : '=' . $originalHome));
            if ($originalProfile !== false) {
                putenv('USERPROFILE=' . $originalProfile);
            }
        }
    }

    public function test_load_from_user_config_registers_servers(): void
    {
        MCPManager::writeUserConfig([
            'fs' => ['type' => 'stdio', 'command' => 'server-filesystem'],
        ], $this->tmpPath);

        $manager = new MCPManager();
        $manager->loadFromUserConfig($this->tmpPath);

        $servers = $manager->getServers();
        $this->assertTrue($servers->has('fs'));
    }

    public function test_load_from_user_config_is_noop_on_missing_file(): void
    {
        $manager = new MCPManager();
        $this->assertSame(0, $manager->getServers()->count());
        $manager->loadFromUserConfig($this->tmpPath);  // file doesn't exist
        $this->assertSame(0, $manager->getServers()->count());
    }
}
