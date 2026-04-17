<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\MCPManager;

class MCPClaudeCodeLoadingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        MCPManager::clear();
        $this->tempDir = sys_get_temp_dir() . '/superagent_mcp_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        MCPManager::clear();
        $this->removeDir($this->tempDir);
    }

    public function test_load_from_mcp_json_stdio(): void
    {
        $config = [
            'mcpServers' => [
                'database' => [
                    'type' => 'stdio',
                    'command' => 'npx',
                    'args' => ['-y', '@bytebase/dbhub'],
                    'env' => [
                        'DATABASE_URL' => 'postgresql://localhost/test',
                    ],
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        $servers = $manager->getServers();
        $this->assertTrue($servers->has('database'));

        $server = $servers->get('database');
        $this->assertEquals('stdio', $server->type);
        $this->assertEquals('npx', $server->config['command']);
        $this->assertEquals(['-y', '@bytebase/dbhub'], $server->config['args']);
        $this->assertEquals('postgresql://localhost/test', $server->config['env']['DATABASE_URL']);
    }

    public function test_load_from_mcp_json_http(): void
    {
        $config = [
            'mcpServers' => [
                'sentry' => [
                    'type' => 'http',
                    'url' => 'https://mcp.sentry.dev/mcp',
                    'headers' => [
                        'Authorization' => 'Bearer test-key',
                    ],
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        $servers = $manager->getServers();
        $this->assertTrue($servers->has('sentry'));

        $server = $servers->get('sentry');
        $this->assertEquals('http', $server->type);
        $this->assertEquals('https://mcp.sentry.dev/mcp', $server->config['url']);
        $this->assertEquals('Bearer test-key', $server->config['headers']['Authorization']);
    }

    public function test_load_from_mcp_json_sse(): void
    {
        $config = [
            'mcpServers' => [
                'sse-server' => [
                    'type' => 'sse',
                    'url' => 'https://api.example.com/sse',
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        $servers = $manager->getServers();
        $this->assertTrue($servers->has('sse-server'));
        $this->assertEquals('sse', $servers->get('sse-server')->type);
    }

    public function test_load_multiple_servers(): void
    {
        $config = [
            'mcpServers' => [
                'github' => [
                    'type' => 'stdio',
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-github'],
                    'env' => ['GITHUB_TOKEN' => 'ghp_test'],
                ],
                'sentry' => [
                    'type' => 'http',
                    'url' => 'https://mcp.sentry.dev/mcp',
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        $this->assertTrue($manager->getServers()->has('github'));
        $this->assertTrue($manager->getServers()->has('sentry'));
    }

    public function test_env_var_expansion(): void
    {
        // Set test env vars
        $_ENV['TEST_MCP_KEY'] = 'my-secret-key';

        $config = [
            'mcpServers' => [
                'api' => [
                    'type' => 'http',
                    'url' => 'https://api.example.com/mcp',
                    'headers' => [
                        'Authorization' => 'Bearer ${TEST_MCP_KEY}',
                    ],
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        $server = $manager->getServers()->get('api');
        $this->assertEquals('Bearer my-secret-key', $server->config['headers']['Authorization']);

        unset($_ENV['TEST_MCP_KEY']);
    }

    public function test_env_var_expansion_with_default(): void
    {
        // Ensure the var is NOT set
        unset($_ENV['NONEXISTENT_VAR']);

        $config = [
            'mcpServers' => [
                'api' => [
                    'type' => 'http',
                    'url' => '${NONEXISTENT_VAR:-https://fallback.example.com}/mcp',
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        $server = $manager->getServers()->get('api');
        $this->assertEquals('https://fallback.example.com/mcp', $server->config['url']);
    }

    public function test_missing_mcp_json_is_noop(): void
    {
        $manager = MCPManager::getInstance();
        $countBefore = $manager->getServers()->count();

        $manager->loadFromClaudeCodeProject($this->tempDir);

        $this->assertEquals($countBefore, $manager->getServers()->count());
    }

    public function test_project_config_takes_precedence(): void
    {
        // Create project-level config
        $projectConfig = [
            'mcpServers' => [
                'myserver' => [
                    'type' => 'http',
                    'url' => 'https://project-level.example.com/mcp',
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($projectConfig));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        // Create a second project dir simulating user-level with same name
        $userDir = $this->tempDir . '/user';
        mkdir($userDir, 0755, true);
        file_put_contents($userDir . '/.mcp.json', json_encode([
            'mcpServers' => [
                'myserver' => [
                    'type' => 'http',
                    'url' => 'https://user-level.example.com/mcp',
                ],
            ],
        ]));

        // loadFromClaudeCodeProject skips already-registered names
        $manager->loadFromClaudeCodeProject($userDir);

        // Should keep project-level URL (registered first)
        $server = $manager->getServers()->get('myserver');
        $this->assertEquals('https://project-level.example.com/mcp', $server->config['url']);
    }

    public function test_load_configuration_accepts_claude_code_format(): void
    {
        $manager = MCPManager::getInstance();
        $manager->loadConfiguration([
            'mcpServers' => [
                'test-server' => [
                    'type' => 'stdio',
                    'command' => 'node',
                    'args' => ['server.js'],
                ],
            ],
        ]);

        $this->assertTrue($manager->getServers()->has('test-server'));
    }

    public function test_default_type_is_stdio(): void
    {
        $config = [
            'mcpServers' => [
                'no-type' => [
                    'command' => 'some-command',
                    'args' => ['--flag'],
                ],
            ],
        ];

        file_put_contents($this->tempDir . '/.mcp.json', json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromClaudeCodeProject($this->tempDir);

        $server = $manager->getServers()->get('no-type');
        $this->assertEquals('stdio', $server->type);
    }

    public function test_load_from_json_file(): void
    {
        $config = [
            'mcpServers' => [
                'custom-server' => [
                    'type' => 'http',
                    'url' => 'https://custom.example.com/mcp',
                ],
            ],
        ];

        $file = $this->tempDir . '/custom-mcp.json';
        file_put_contents($file, json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromJsonFile($file);

        $this->assertTrue($manager->getServers()->has('custom-server'));
        $this->assertEquals('https://custom.example.com/mcp', $manager->getServers()->get('custom-server')->config['url']);
    }

    public function test_load_from_json_file_nonexistent_is_noop(): void
    {
        $manager = MCPManager::getInstance();
        $countBefore = $manager->getServers()->count();

        $manager->loadFromJsonFile('/nonexistent/config.json');

        $this->assertEquals($countBefore, $manager->getServers()->count());
    }

    public function test_load_from_json_file_superagent_format(): void
    {
        $config = [
            'servers' => [
                'sa-server' => [
                    'type' => 'stdio',
                    'command' => 'node',
                    'args' => ['server.js'],
                ],
            ],
        ];

        $file = $this->tempDir . '/sa-mcp.json';
        file_put_contents($file, json_encode($config));

        $manager = MCPManager::getInstance();
        $manager->loadFromJsonFile($file);

        $this->assertTrue($manager->getServers()->has('sa-server'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
