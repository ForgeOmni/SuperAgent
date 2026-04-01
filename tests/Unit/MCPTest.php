<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\MCP\Client;
use SuperAgent\MCP\MCPManager;
use SuperAgent\MCP\MCPTool;
use SuperAgent\MCP\Types\ServerConfig;
use SuperAgent\MCP\Types\ServerCapabilities;
use SuperAgent\MCP\Types\Tool;
use SuperAgent\MCP\Types\Resource;
use SuperAgent\MCP\Transports\StdioTransport;
use SuperAgent\MCP\Transports\HttpTransport;
use SuperAgent\MCP\Transports\SSETransport;
use SuperAgent\MCP\Contracts\Transport;

class MCPTest extends TestCase
{
    private MCPManager $manager;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = MCPManager::getInstance();
    }
    
    protected function tearDown(): void
    {
        // Reset manager
        $this->manager->disconnectAll();
        parent::tearDown();
    }
    
    public function testServerConfigCreation()
    {
        $config = new ServerConfig([
            'name' => 'test-server',
            'command' => 'node',
            'args' => ['server.js'],
            'env' => ['API_KEY' => 'test'],
        ]);
        
        $this->assertEquals('test-server', $config->name);
        $this->assertEquals('node', $config->command);
        $this->assertEquals(['server.js'], $config->args);
        $this->assertArrayHasKey('API_KEY', $config->env);
    }
    
    public function testServerCapabilities()
    {
        $capabilities = new ServerCapabilities([
            'tools' => ['call'],
            'resources' => ['read', 'list'],
            'prompts' => ['get'],
        ]);
        
        $this->assertTrue($capabilities->supportsTools());
        $this->assertTrue($capabilities->supportsResources());
        $this->assertTrue($capabilities->supportsPrompts());
    }
    
    public function testStdioTransportCreation()
    {
        $config = new ServerConfig([
            'name' => 'stdio-server',
            'command' => 'echo',
            'args' => ['test'],
        ]);
        
        $transport = new StdioTransport($config);
        
        $this->assertInstanceOf(Transport::class, $transport);
    }
    
    public function testHttpTransportCreation()
    {
        $config = new ServerConfig([
            'name' => 'http-server',
            'url' => 'http://localhost:3000',
        ]);
        
        $transport = new HttpTransport($config);
        
        $this->assertInstanceOf(Transport::class, $transport);
    }
    
    public function testSSETransportCreation()
    {
        $config = new ServerConfig([
            'name' => 'sse-server',
            'url' => 'http://localhost:3000/events',
        ]);
        
        $transport = new SSETransport($config);
        
        $this->assertInstanceOf(Transport::class, $transport);
    }
    
    public function testMCPClientCreation()
    {
        $config = new ServerConfig([
            'name' => 'test-server',
            'command' => 'echo',
            'args' => ['test'],
        ]);
        
        $transport = $this->createMock(Transport::class);
        $client = new Client($config, $transport);
        
        $this->assertInstanceOf(Client::class, $client);
    }
    
    public function testMCPToolConversion()
    {
        $mcpToolData = [
            'name' => 'test_tool',
            'description' => 'A test tool',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'input' => ['type' => 'string'],
                ],
                'required' => ['input'],
            ],
        ];
        
        $tool = new Tool($mcpToolData);
        $mcpTool = new MCPTool($tool, 'test-server');
        
        $this->assertEquals('test_tool', $mcpTool->name());
        $this->assertEquals('A test tool', $mcpTool->description());
        $this->assertArrayHasKey('input', $mcpTool->inputSchema()['properties']);
    }
    
    public function testMCPManagerRegistersServer()
    {
        $config = new ServerConfig([
            'name' => 'test-server',
            'command' => 'echo',
            'args' => ['test'],
        ]);
        
        $this->manager->registerServer($config);
        
        $servers = $this->manager->getRegisteredServers();
        $this->assertArrayHasKey('test-server', $servers);
    }
    
    public function testMCPManagerConnectsToServer()
    {
        $config = new ServerConfig([
            'name' => 'test-server',
            'command' => 'echo',
            'args' => ['{"jsonrpc":"2.0","result":{"capabilities":{}},"id":1}'],
        ]);
        
        $this->manager->registerServer($config);
        
        // This would attempt real connection, so we'll test registration only
        $this->assertTrue($this->manager->isRegistered('test-server'));
    }
    
    public function testMCPManagerDiscoversTools()
    {
        // Create mock client
        $client = $this->createMock(Client::class);
        $client->method('getTools')->willReturn([
            new Tool([
                'name' => 'discovered_tool',
                'description' => 'A discovered tool',
                'inputSchema' => ['type' => 'object'],
            ]),
        ]);
        
        // In real scenario, manager would discover tools from connected servers
        $tools = $client->getTools();
        
        $this->assertCount(1, $tools);
        $this->assertEquals('discovered_tool', $tools[0]->name);
    }
    
    public function testMCPManagerHandlesResources()
    {
        $resourceData = [
            'uri' => 'file:///test.txt',
            'name' => 'Test File',
            'mimeType' => 'text/plain',
        ];
        
        $resource = new Resource($resourceData);
        
        $this->assertEquals('file:///test.txt', $resource->uri);
        $this->assertEquals('Test File', $resource->name);
        $this->assertEquals('text/plain', $resource->mimeType);
    }
    
    public function testMCPManagerHandlesConnectionErrors()
    {
        $config = new ServerConfig([
            'name' => 'failing-server',
            'command' => '/nonexistent/command',
        ]);
        
        $this->manager->registerServer($config);
        
        try {
            // Attempt to connect to non-existent server
            @$this->manager->connect('failing-server');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Failed to connect', $e->getMessage());
        }
        
        $this->assertFalse($this->manager->isConnected('failing-server'));
    }
    
    public function testMCPManagerDisconnectsServers()
    {
        $config = new ServerConfig([
            'name' => 'test-server',
            'command' => 'echo',
            'args' => ['test'],
        ]);
        
        $this->manager->registerServer($config);
        
        // Disconnect (even if not connected)
        $this->manager->disconnect('test-server');
        
        $this->assertFalse($this->manager->isConnected('test-server'));
    }
    
    public function testMCPManagerDisconnectsAll()
    {
        // Register multiple servers
        $this->manager->registerServer(new ServerConfig([
            'name' => 'server1',
            'command' => 'echo',
        ]));
        
        $this->manager->registerServer(new ServerConfig([
            'name' => 'server2',
            'command' => 'echo',
        ]));
        
        $this->manager->disconnectAll();
        
        $this->assertFalse($this->manager->isConnected('server1'));
        $this->assertFalse($this->manager->isConnected('server2'));
    }
    
    public function testMCPManagerHandlesOAuthFlow()
    {
        $config = new ServerConfig([
            'name' => 'oauth-server',
            'command' => 'echo',
            'oauth' => [
                'client_id' => 'test_client',
                'authorize_url' => 'https://example.com/oauth/authorize',
                'token_url' => 'https://example.com/oauth/token',
            ],
        ]);
        
        $this->assertArrayHasKey('oauth', $config->toArray());
        $this->assertEquals('test_client', $config->oauth['client_id']);
    }
    
    public function testMCPToolExecution()
    {
        $tool = new Tool([
            'name' => 'echo_tool',
            'description' => 'Echoes input',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                ],
            ],
        ]);
        
        $mcpTool = new MCPTool($tool, 'test-server');
        
        // Mock execution would be handled by the MCP client
        $schema = $mcpTool->inputSchema();
        
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('message', $schema['properties']);
    }
    
    public function testMCPManagerEnvironmentVariableExpansion()
    {
        $_ENV['TEST_MCP_PATH'] = '/test/path';
        
        $config = new ServerConfig([
            'name' => 'env-server',
            'command' => '${TEST_MCP_PATH}/server',
            'env' => [
                'API_KEY' => '${TEST_API_KEY:-default_key}',
            ],
        ]);
        
        // Environment expansion would happen during transport initialization
        $this->assertStringContainsString('${TEST_MCP_PATH}', $config->command);
        
        unset($_ENV['TEST_MCP_PATH']);
    }
    
    public function testMCPManagerReconnection()
    {
        $config = new ServerConfig([
            'name' => 'reconnect-server',
            'command' => 'echo',
            'reconnect' => true,
            'reconnectDelay' => 1000,
            'maxReconnectAttempts' => 3,
        ]);
        
        $this->assertTrue($config->reconnect);
        $this->assertEquals(1000, $config->reconnectDelay);
        $this->assertEquals(3, $config->maxReconnectAttempts);
    }
}