<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\MCPManager;
use SuperAgent\MCP\Client;
use SuperAgent\MCP\MCPTool;
use SuperAgent\MCP\Types\ServerConfig;
use SuperAgent\MCP\Types\ServerCapabilities;
use SuperAgent\MCP\Types\Tool;
use SuperAgent\MCP\Types\Resource;
use SuperAgent\MCP\Types\Prompt;
use SuperAgent\MCP\Transports\StdioTransport;
use SuperAgent\MCP\Transports\SSETransport;
use SuperAgent\MCP\Transports\HttpTransport;
use SuperAgent\Tools\Builtin\ListMcpResourcesTool;

class Phase9MCPTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear MCP Manager state
        MCPManager::clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clear MCP Manager state
        MCPManager::clear();
    }

    /**
     * Test MCPManager singleton pattern
     */
    public function testMCPManagerSingleton(): void
    {
        $manager1 = MCPManager::getInstance();
        $manager2 = MCPManager::getInstance();
        
        $this->assertSame($manager1, $manager2, 'MCPManager should implement singleton pattern');
    }

    /**
     * Test ServerConfig creation methods
     */
    public function testServerConfigCreation(): void
    {
        // Test stdio config
        $stdio = ServerConfig::stdio('test-stdio', 'python', ['script.py'], ['ENV_VAR' => 'value']);
        $this->assertEquals('test-stdio', $stdio->name);
        $this->assertEquals('stdio', $stdio->type);
        $this->assertEquals('python', $stdio->config['command']);
        $this->assertEquals(['script.py'], $stdio->config['args']);
        $this->assertEquals(['ENV_VAR' => 'value'], $stdio->config['env']);

        // Test SSE config
        $sse = ServerConfig::sse('test-sse', 'http://localhost:3000', ['Authorization' => 'Bearer token']);
        $this->assertEquals('test-sse', $sse->name);
        $this->assertEquals('sse', $sse->type);
        $this->assertEquals('http://localhost:3000', $sse->config['url']);
        $this->assertEquals(['Authorization' => 'Bearer token'], $sse->config['headers']);

        // Test HTTP config
        $http = ServerConfig::http('test-http', 'http://localhost:8080');
        $this->assertEquals('test-http', $http->name);
        $this->assertEquals('http', $http->type);
        $this->assertEquals('http://localhost:8080', $http->config['url']);
    }

    /**
     * Test ServerCapabilities
     */
    public function testServerCapabilities(): void
    {
        $capabilities = new ServerCapabilities([
            'tools' => ['call'],
            'resources' => ['read', 'list'],
            'prompts' => ['get', 'list'],
            'logging' => ['setLevel'],
        ]);

        $this->assertTrue($capabilities->hasTools());
        $this->assertTrue($capabilities->hasResources());
        $this->assertTrue($capabilities->hasPrompts());
        $this->assertTrue($capabilities->hasLogging());
        
        $this->assertTrue($capabilities->canCallTools());
        $this->assertTrue($capabilities->canReadResources());
        $this->assertTrue($capabilities->canListResources());
        $this->assertTrue($capabilities->canGetPrompts());
        $this->assertTrue($capabilities->canListPrompts());
    }

    /**
     * Test MCP Tool type
     */
    public function testMCPToolType(): void
    {
        $tool = new Tool(
            name: 'test_tool',
            description: 'A test tool',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'input' => ['type' => 'string'],
                ],
                'required' => ['input'],
            ]
        );

        $this->assertEquals('test_tool', $tool->name);
        $this->assertEquals('A test tool', $tool->description);
        $this->assertEquals('mcp_server1_test_tool', $tool->getFullName('server1'));
        
        // Test input validation
        $this->assertTrue($tool->validateInput(['input' => 'test']));
        $this->assertFalse($tool->validateInput([])); // Missing required field
    }

    /**
     * Test Resource type
     */
    public function testResourceType(): void
    {
        $resource = new Resource(
            uri: 'file:///path/to/resource',
            name: 'Test Resource',
            description: 'A test resource',
            mimeType: 'text/plain'
        );

        $this->assertEquals('file:///path/to/resource', $resource->uri);
        $this->assertEquals('Test Resource', $resource->name);
        $this->assertEquals('A test resource', $resource->description);
        $this->assertEquals('text/plain', $resource->mimeType);

        $array = $resource->toArray();
        $this->assertArrayHasKey('uri', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('mimeType', $array);
    }

    /**
     * Test Prompt type
     */
    public function testPromptType(): void
    {
        $prompt = new Prompt(
            name: 'test_prompt',
            description: 'A test prompt',
            arguments: [
                ['name' => 'arg1', 'description' => 'First argument', 'required' => true],
            ]
        );

        $this->assertEquals('test_prompt', $prompt->name);
        $this->assertEquals('A test prompt', $prompt->description);
        $this->assertCount(1, $prompt->arguments);
    }

    /**
     * Test StdioTransport creation
     */
    public function testStdioTransport(): void
    {
        $transport = new StdioTransport('echo', ['hello']);
        
        $this->assertInstanceOf(StdioTransport::class, $transport);
        $this->assertFalse($transport->isConnected());
        
        // Note: Actual connection testing would require a real MCP server
    }

    /**
     * Test SSETransport creation
     */
    public function testSSETransport(): void
    {
        $transport = new SSETransport('http://localhost:3000', ['X-API-Key' => 'test']);
        
        $this->assertInstanceOf(SSETransport::class, $transport);
        $this->assertFalse($transport->isConnected());
    }

    /**
     * Test HttpTransport creation
     */
    public function testHttpTransport(): void
    {
        $transport = new HttpTransport('http://localhost:8080');
        
        $this->assertInstanceOf(HttpTransport::class, $transport);
        $this->assertFalse($transport->isConnected());
    }

    /**
     * Test MCPManager server registration
     */
    public function testMCPManagerServerRegistration(): void
    {
        $manager = MCPManager::getInstance();
        
        $config1 = ServerConfig::stdio('server1', 'python', ['mcp_server.py']);
        $config2 = ServerConfig::http('server2', 'http://localhost:8080');
        
        $manager->registerServer($config1);
        $manager->registerServer($config2);
        
        $servers = $manager->getServers();
        $this->assertCount(2, $servers);
        $this->assertTrue($servers->has('server1'));
        $this->assertTrue($servers->has('server2'));
    }

    /**
     * Test MCPManager configuration loading
     */
    public function testMCPManagerConfigurationLoading(): void
    {
        $manager = MCPManager::getInstance();
        
        $config = [
            'servers' => [
                'filesystem' => [
                    'type' => 'stdio',
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-filesystem'],
                ],
                'github' => [
                    'type' => 'http',
                    'url' => 'https://api.github.com/mcp',
                    'headers' => ['Authorization' => 'token abc123'],
                ],
                'slack' => [
                    'type' => 'sse',
                    'url' => 'https://slack.com/mcp/sse',
                ],
            ],
        ];
        
        $manager->loadConfiguration($config);
        
        $servers = $manager->getServers();
        $this->assertCount(3, $servers);
        $this->assertTrue($servers->has('filesystem'));
        $this->assertTrue($servers->has('github'));
        $this->assertTrue($servers->has('slack'));
        
        $filesystem = $servers->get('filesystem');
        $this->assertEquals('stdio', $filesystem->type);
        $this->assertEquals('npx', $filesystem->config['command']);
    }

    /**
     * Test MCPTool wrapper
     */
    public function testMCPToolWrapper(): void
    {
        // Create a mock client
        $config = ServerConfig::http('test-server', 'http://localhost');
        $transport = new HttpTransport('http://localhost');
        $client = new Client($config, $transport);
        
        // Create an MCP tool type
        $mcpToolType = new Tool(
            name: 'search',
            description: 'Search for files',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
            ]
        );
        
        // Create MCPTool wrapper
        $tool = new MCPTool($client, 'test-server', $mcpToolType);
        
        $this->assertEquals('mcp_test-server_search', $tool->name());
        $this->assertStringContainsString('[MCP:test-server]', $tool->description());
        $this->assertEquals('mcp', $tool->category());
        $this->assertTrue($tool->isReadOnly()); // 'search' implies read-only
        
        // Test non-readonly tool
        $writeTool = new Tool(
            name: 'create_file',
            description: 'Create a file',
            inputSchema: []
        );
        $writeToolWrapper = new MCPTool($client, 'test-server', $writeTool);
        $this->assertFalse($writeToolWrapper->isReadOnly());
    }

    /**
     * Test ListMcpResourcesTool
     */
    public function testListMcpResourcesTool(): void
    {
        $tool = new ListMcpResourcesTool();
        
        $this->assertEquals('list_mcp_resources', $tool->name());
        $this->assertEquals('mcp', $tool->category());
        $this->assertTrue($tool->isReadOnly());
        
        // Test with no resources
        $result = $tool->execute([]);
        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('No MCP resources available', $result->data);
    }

    /**
     * Test MCPManager tool search
     */
    public function testMCPManagerToolSearch(): void
    {
        $manager = MCPManager::getInstance();
        
        // Register a server
        $config = ServerConfig::http('test-server', 'http://localhost');
        $manager->registerServer($config);
        
        // Since we can't actually connect in tests, we'll test the search logic
        $tools = $manager->searchTools('nonexistent');
        $this->assertCount(0, $tools);
    }

    /**
     * Test error handling
     */
    public function testErrorHandling(): void
    {
        $manager = MCPManager::getInstance();
        
        // Test connecting to non-existent server
        try {
            $manager->connect('nonexistent');
            $this->fail('Should throw exception for non-existent server');
        } catch (\Exception $e) {
            $this->assertStringContainsString('not registered', $e->getMessage());
        }
        
        // Test disabled server
        $config = new ServerConfig(
            name: 'disabled-server',
            type: 'stdio',
            config: ['command' => 'test'],
            enabled: false
        );
        $manager->registerServer($config);
        
        try {
            $manager->connect('disabled-server');
            $this->fail('Should throw exception for disabled server');
        } catch (\Exception $e) {
            $this->assertStringContainsString('is disabled', $e->getMessage());
        }
    }

    /**
     * Test transport type creation
     */
    public function testTransportTypeCreation(): void
    {
        $manager = MCPManager::getInstance();
        
        // Test unsupported transport type
        $config = new ServerConfig(
            name: 'websocket-server',
            type: 'websocket', // Unsupported type
            config: [],
        );
        $manager->registerServer($config);
        
        try {
            $manager->connect('websocket-server');
            $this->fail('Should throw exception for unsupported transport');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Unsupported transport type', $e->getMessage());
        }
    }
}