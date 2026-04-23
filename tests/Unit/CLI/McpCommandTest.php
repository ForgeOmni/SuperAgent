<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\Commands\McpCommand;
use SuperAgent\MCP\MCPManager;

/**
 * Exercises the subcommand routing of McpCommand by redirecting HOME to
 * a temp dir so `add`/`remove` mutate a throwaway file rather than the
 * developer's real ~/.superagent/mcp.json.
 */
class McpCommandTest extends TestCase
{
    private string $tmpHome;
    private ?string $origHome;
    private ?string $origProfile;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/superagent_mcpcli_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpHome, 0755, true);
        $this->origHome = getenv('HOME') ?: null;
        $this->origProfile = getenv('USERPROFILE') ?: null;
        putenv('HOME=' . $this->tmpHome);
        putenv('USERPROFILE=' . $this->tmpHome);
    }

    protected function tearDown(): void
    {
        $path = MCPManager::userConfigPath();
        if (is_file($path)) @unlink($path);
        $tmp = $path . '.tmp';
        if (is_file($tmp)) @unlink($tmp);
        if (is_dir($this->tmpHome . '/.superagent')) @rmdir($this->tmpHome . '/.superagent');
        if (is_dir($this->tmpHome)) @rmdir($this->tmpHome);

        putenv('HOME' . ($this->origHome === null ? '' : '=' . $this->origHome));
        putenv('USERPROFILE' . ($this->origProfile === null ? '' : '=' . $this->origProfile));
    }

    public function test_list_on_empty_config_exits_zero(): void
    {
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['list']]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function test_add_stdio_server_writes_config(): void
    {
        ob_start();
        $code = (new McpCommand())->execute([
            'mcp_args' => ['add', 'fs', 'stdio', 'npx',
                '--arg', '-y',
                '--arg', '@modelcontextprotocol/server-filesystem',
                '--arg', '/tmp',
                '--env', 'FOO=bar',
            ],
        ]);
        ob_end_clean();

        $this->assertSame(0, $code);

        $servers = MCPManager::readUserConfig();
        $this->assertArrayHasKey('fs', $servers);
        $this->assertSame('stdio', $servers['fs']['type']);
        $this->assertSame('npx', $servers['fs']['command']);
        $this->assertSame(
            ['-y', '@modelcontextprotocol/server-filesystem', '/tmp'],
            $servers['fs']['args'],
        );
        $this->assertSame(['FOO' => 'bar'], $servers['fs']['env']);
    }

    public function test_add_http_server_with_headers(): void
    {
        ob_start();
        $code = (new McpCommand())->execute([
            'mcp_args' => ['add', 'search', 'http', 'https://mcp.example/search',
                '--header', 'Authorization: Bearer abc',
                '--header', 'X-Trace: 1',
            ],
        ]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $servers = MCPManager::readUserConfig();
        $this->assertSame('http', $servers['search']['type']);
        $this->assertSame('https://mcp.example/search', $servers['search']['url']);
        $this->assertSame('Bearer abc', $servers['search']['headers']['Authorization']);
        $this->assertSame('1', $servers['search']['headers']['X-Trace']);
    }

    public function test_add_rejects_invalid_type(): void
    {
        ob_start();
        $code = (new McpCommand())->execute([
            'mcp_args' => ['add', 'bad', 'grpc', 'something'],
        ]);
        ob_end_clean();
        $this->assertSame(2, $code);
        $this->assertSame([], MCPManager::readUserConfig());
    }

    public function test_remove_deletes_entry(): void
    {
        MCPManager::writeUserConfig([
            'x' => ['type' => 'stdio', 'command' => 'x'],
            'y' => ['type' => 'stdio', 'command' => 'y'],
        ]);

        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['remove', 'x']]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $servers = MCPManager::readUserConfig();
        $this->assertArrayNotHasKey('x', $servers);
        $this->assertArrayHasKey('y', $servers);
    }

    public function test_remove_on_unknown_name_is_noop(): void
    {
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['remove', 'does-not-exist']]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function test_unknown_subcommand_returns_2(): void
    {
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['nope']]);
        ob_end_clean();
        $this->assertSame(2, $code);
    }

    public function test_path_subcommand_emits_config_path(): void
    {
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['path']]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('mcp.json', $out);
    }

    public function test_auth_on_unknown_server_returns_1(): void
    {
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['auth', 'nope']]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function test_auth_on_server_without_oauth_block_returns_2(): void
    {
        MCPManager::writeUserConfig([
            'plain' => ['type' => 'stdio', 'command' => 'echo'],
        ]);
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['auth', 'plain']]);
        $out = ob_get_clean();
        $this->assertSame(2, $code);
        $this->assertStringContainsString('oauth', $out);
    }

    public function test_reset_auth_always_succeeds(): void
    {
        // Even without a prior token, reset-auth should be a no-op success
        // — this matches logout-style semantics in other tools.
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['reset-auth', 'any-name']]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function test_reset_auth_requires_name(): void
    {
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['reset-auth']]);
        ob_end_clean();
        $this->assertSame(2, $code);
    }

    public function test_test_on_stdio_server_with_existing_binary(): void
    {
        MCPManager::writeUserConfig([
            'echo' => ['type' => 'stdio', 'command' => 'echo'],
        ]);
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['test', 'echo']]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function test_test_on_stdio_server_with_nonexistent_binary(): void
    {
        MCPManager::writeUserConfig([
            'bogus' => ['type' => 'stdio', 'command' => 'definitely-not-a-real-binary-x91234'],
        ]);
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['test', 'bogus']]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function test_test_requires_name(): void
    {
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['test']]);
        ob_end_clean();
        $this->assertSame(2, $code);
    }

    public function test_list_tags_oauth_servers_as_auth_needed(): void
    {
        MCPManager::writeUserConfig([
            'linear' => [
                'type' => 'http',
                'url' => 'https://mcp.linear.app/sse',
                'oauth' => [
                    'client_id' => 'test-client',
                    'device_endpoint' => 'https://auth.linear.app/oauth/device',
                    'token_endpoint' => 'https://auth.linear.app/oauth/token',
                ],
            ],
        ]);
        ob_start();
        $code = (new McpCommand())->execute(['mcp_args' => ['list']]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('linear', $out);
        $this->assertStringContainsString('auth: needed', $out);
    }
}
