<?php

namespace SuperAgent\Tests\Unit\Bridge\Enhancers;

use Orchestra\Testbench\TestCase;
use SuperAgent\Bridge\BridgeToolProxy;
use SuperAgent\Bridge\Enhancers\ToolSchemaEnhancer;
use SuperAgent\Messages\AssistantMessage;

class ToolSchemaEnhancerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\SuperAgent\SuperAgentServiceProvider::class];
    }

    public function test_fixes_empty_properties(): void
    {
        $enhancer = new ToolSchemaEnhancer();

        $tools = [
            new BridgeToolProxy('test_tool', 'A test tool', [
                'type' => 'object',
                'properties' => [],
            ]),
        ];

        $messages = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $schema = $tools[0]->inputSchema();
        // Empty properties should be an object, not array
        $this->assertIsObject($schema['properties']);
    }

    public function test_preserves_non_empty_properties(): void
    {
        $enhancer = new ToolSchemaEnhancer();

        $tools = [
            new BridgeToolProxy('test_tool', 'A test tool', [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
                'required' => ['name'],
            ]),
        ];

        $messages = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        $schema = $tools[0]->inputSchema();
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertSame('string', $schema['properties']['name']['type']);
    }

    public function test_non_bridge_tools_ignored(): void
    {
        $enhancer = new ToolSchemaEnhancer();

        $mockTool = $this->createMock(\SuperAgent\Contracts\ToolInterface::class);

        $tools = [$mockTool];
        $messages = [];
        $prompt = null;
        $options = [];
        $enhancer->enhanceRequest($messages, $tools, $prompt, $options);

        // Should not throw, mock tool unchanged
        $this->assertSame($mockTool, $tools[0]);
    }

    public function test_response_passthrough(): void
    {
        $enhancer = new ToolSchemaEnhancer();
        $msg = new AssistantMessage();
        $this->assertSame($msg, $enhancer->enhanceResponse($msg));
    }
}
