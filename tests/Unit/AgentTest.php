<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;
use SuperAgent\Tools\ToolResult;
use SuperAgent\StreamingHandler;

/**
 * Unit tests for Agent class — initialization, provider routing,
 * tool loading, fluent API, and error paths.
 */
class AgentTest extends TestCase
{
    // ========================================================================
    // CONSTRUCTION & PROVIDER ROUTING
    // ========================================================================

    public function test_construct_with_provider_instance(): void
    {
        $mockProvider = $this->createMockProvider('test-provider', 'test-model');

        $agent = new Agent(['provider' => $mockProvider]);

        $this->assertSame($mockProvider, $agent->getProvider());
    }

    public function test_construct_with_invalid_provider_throws(): void
    {
        $this->expectException(\SuperAgent\Exceptions\ProviderException::class);
        $this->expectExceptionMessage('Unknown provider');

        new Agent(['provider' => 'nonexistent_driver_xyz']);
    }

    public function test_construct_default_max_turns(): void
    {
        $agent = new Agent(['provider' => $this->createMockProvider()]);

        // Default max_turns is 50 (from Agent.php line 57)
        $ref = new \ReflectionProperty(Agent::class, 'maxTurns');
        $ref->setAccessible(true);
        $this->assertEquals(50, $ref->getValue($agent));
    }

    public function test_construct_custom_max_turns(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'max_turns' => 10,
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'maxTurns');
        $ref->setAccessible(true);
        $this->assertEquals(10, $ref->getValue($agent));
    }

    public function test_construct_max_budget(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'max_budget_usd' => 5.0,
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'maxBudgetUsd');
        $ref->setAccessible(true);
        $this->assertEquals(5.0, $ref->getValue($agent));
    }

    public function test_construct_system_prompt(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'system_prompt' => 'You are a helpful assistant.',
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'systemPrompt');
        $ref->setAccessible(true);
        $this->assertEquals('You are a helpful assistant.', $ref->getValue($agent));
    }

    public function test_construct_with_allowed_tools(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'allowed_tools' => ['read', 'grep'],
            'load_tools' => 'none',
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'allowedTools');
        $ref->setAccessible(true);
        $this->assertEquals(['read', 'grep'], $ref->getValue($agent));
    }

    public function test_construct_with_denied_tools(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'denied_tools' => ['bash'],
            'load_tools' => 'none',
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'deniedTools');
        $ref->setAccessible(true);
        $this->assertEquals(['bash'], $ref->getValue($agent));
    }

    public function test_construct_no_tools_loaded(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'tools');
        $ref->setAccessible(true);
        $this->assertEmpty($ref->getValue($agent));
    }

    public function test_construct_explicit_tools(): void
    {
        $tool = $this->createMockTool('my_tool');

        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'tools' => [$tool],
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'tools');
        $ref->setAccessible(true);
        $tools = $ref->getValue($agent);
        $this->assertCount(1, $tools);
        $this->assertEquals('my_tool', $tools[0]->name());
    }

    public function test_construct_with_streaming_handler(): void
    {
        $handler = new StreamingHandler();

        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'streaming_handler' => $handler,
            'load_tools' => 'none',
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'streamingHandler');
        $ref->setAccessible(true);
        $this->assertSame($handler, $ref->getValue($agent));
    }

    // ========================================================================
    // FLUENT API
    // ========================================================================

    public function test_with_system_prompt(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $result = $agent->withSystemPrompt('Be concise');

        $this->assertSame($agent, $result); // Returns self
        $ref = new \ReflectionProperty(Agent::class, 'systemPrompt');
        $ref->setAccessible(true);
        $this->assertEquals('Be concise', $ref->getValue($agent));
    }

    public function test_with_model(): void
    {
        $mockProvider = $this->createMockProvider();
        $mockProvider->expects($this->once())
            ->method('setModel')
            ->with($this->callback(fn($model) => is_string($model) && strlen($model) > 0));

        $agent = new Agent([
            'provider' => $mockProvider,
            'load_tools' => 'none',
        ]);

        $result = $agent->withModel('sonnet');
        $this->assertSame($agent, $result);
    }

    public function test_with_max_turns(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $result = $agent->withMaxTurns(5);

        $this->assertSame($agent, $result);
        $ref = new \ReflectionProperty(Agent::class, 'maxTurns');
        $ref->setAccessible(true);
        $this->assertEquals(5, $ref->getValue($agent));
    }

    public function test_with_max_budget(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $result = $agent->withMaxBudget(2.50);

        $this->assertSame($agent, $result);
        $ref = new \ReflectionProperty(Agent::class, 'maxBudgetUsd');
        $ref->setAccessible(true);
        $this->assertEquals(2.50, $ref->getValue($agent));
    }

    public function test_with_options_merges(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'options' => ['a' => 1],
            'load_tools' => 'none',
        ]);

        $agent->withOptions(['b' => 2]);

        $ref = new \ReflectionProperty(Agent::class, 'options');
        $ref->setAccessible(true);
        $options = $ref->getValue($agent);
        $this->assertEquals(1, $options['a']);
        $this->assertEquals(2, $options['b']);
    }

    public function test_with_allowed_tools(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $result = $agent->withAllowedTools(['read', 'edit']);

        $this->assertSame($agent, $result);
        $ref = new \ReflectionProperty(Agent::class, 'allowedTools');
        $ref->setAccessible(true);
        $this->assertEquals(['read', 'edit'], $ref->getValue($agent));
    }

    public function test_with_denied_tools(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $agent->withDeniedTools(['bash', 'write']);

        $ref = new \ReflectionProperty(Agent::class, 'deniedTools');
        $ref->setAccessible(true);
        $this->assertEquals(['bash', 'write'], $ref->getValue($agent));
    }

    public function test_with_auto_mode(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $result = $agent->withAutoMode(true, ['max_agents' => 3]);

        $this->assertSame($agent, $result);
        $ref = new \ReflectionProperty(Agent::class, 'autoMode');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($agent));
    }

    // ========================================================================
    // TOOL MANAGEMENT
    // ========================================================================

    public function test_add_tool(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $tool = $this->createMockTool('custom_tool');
        $result = $agent->addTool($tool);

        $this->assertSame($agent, $result);
        $ref = new \ReflectionProperty(Agent::class, 'tools');
        $ref->setAccessible(true);
        $tools = $ref->getValue($agent);
        $this->assertCount(1, $tools);
        $this->assertEquals('custom_tool', $tools[0]->name());
    }

    public function test_add_multiple_tools(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $agent->addTool($this->createMockTool('tool_a'));
        $agent->addTool($this->createMockTool('tool_b'));

        $ref = new \ReflectionProperty(Agent::class, 'tools');
        $ref->setAccessible(true);
        $this->assertCount(2, $ref->getValue($agent));
    }

    public function test_load_tools_false_gives_empty(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => false,
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'tools');
        $ref->setAccessible(true);
        $this->assertEmpty($ref->getValue($agent));
    }

    // ========================================================================
    // MESSAGE MANAGEMENT
    // ========================================================================

    public function test_get_messages_initially_empty(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $this->assertEmpty($agent->getMessages());
    }

    public function test_clear_returns_self(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $result = $agent->clear();
        $this->assertSame($agent, $result);
        $this->assertEmpty($agent->getMessages());
    }

    // ========================================================================
    // PROVIDER ACCESS
    // ========================================================================

    public function test_get_provider(): void
    {
        $mockProvider = $this->createMockProvider('my-provider');

        $agent = new Agent(['provider' => $mockProvider, 'load_tools' => 'none']);

        $provider = $agent->getProvider();
        $this->assertSame($mockProvider, $provider);
        $this->assertEquals('my-provider', $provider->name());
    }

    // ========================================================================
    // CREATE ENGINE
    // ========================================================================

    public function test_create_engine_returns_query_engine(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $ref = new \ReflectionMethod(Agent::class, 'createEngine');
        $ref->setAccessible(true);
        $engine = $ref->invoke($agent, null);

        $this->assertInstanceOf(\SuperAgent\QueryEngine::class, $engine);
    }

    // ========================================================================
    // AUTO MODE
    // ========================================================================

    public function test_auto_mode_default_disabled(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'autoMode');
        $ref->setAccessible(true);
        $this->assertFalse($ref->getValue($agent));
    }

    public function test_auto_mode_enabled_via_config(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'auto_mode' => true,
            'load_tools' => 'none',
        ]);

        $ref = new \ReflectionProperty(Agent::class, 'autoMode');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($agent));
    }

    // ========================================================================
    // PROVIDER INJECTION INTO AGENT TOOLS
    // ========================================================================

    public function test_provider_config_injection_into_agent_tools(): void
    {
        $mockProvider = $this->createMockProvider('anthropic', 'claude-sonnet-4-6');

        // Create a mock AgentTool
        $agentTool = $this->createMock(\SuperAgent\Tools\Builtin\AgentTool::class);
        $agentTool->expects($this->once())
            ->method('setProviderConfig')
            ->with($this->callback(function (array $config) {
                return isset($config['provider'])
                    && $config['provider'] === 'anthropic'
                    && isset($config['model'])
                    && $config['model'] === 'claude-sonnet-4-6';
            }));
        $agentTool->method('name')->willReturn('agent');
        $agentTool->method('description')->willReturn('Agent tool');
        $agentTool->method('inputSchema')->willReturn([]);
        $agentTool->method('isReadOnly')->willReturn(false);

        new Agent([
            'provider' => $mockProvider,
            'tools' => [$agentTool],
        ]);
    }

    // ========================================================================
    // BRIDGE MODE
    // ========================================================================

    public function test_anthropic_provider_not_wrapped_with_bridge(): void
    {
        $mockProvider = $this->createMockProvider('anthropic');

        $agent = new Agent([
            'provider' => $mockProvider,
            'bridge_mode' => true, // Explicitly enabled but should be ignored for Anthropic
            'load_tools' => 'none',
        ]);

        $this->assertSame($mockProvider, $agent->getProvider());
    }

    // ========================================================================
    // CHAINING
    // ========================================================================

    public function test_fluent_chaining(): void
    {
        $agent = new Agent([
            'provider' => $this->createMockProvider(),
            'load_tools' => 'none',
        ]);

        $result = $agent
            ->withSystemPrompt('Be helpful')
            ->withMaxTurns(20)
            ->withMaxBudget(3.0)
            ->withAllowedTools(['read', 'edit'])
            ->withDeniedTools(['bash'])
            ->withAutoMode(false);

        $this->assertSame($agent, $result);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function createMockProvider(string $name = 'anthropic', string $model = 'test-model'): LLMProvider
    {
        $mock = $this->createMock(LLMProvider::class);
        $mock->method('name')->willReturn($name);
        $mock->method('getModel')->willReturn($model);
        $mock->method('setModel')->willReturnCallback(function () {});
        $mock->method('formatMessages')->willReturn([]);
        $mock->method('formatTools')->willReturn([]);
        return $mock;
    }

    private function createMockTool(string $name): ToolInterface
    {
        $mock = $this->createMock(ToolInterface::class);
        $mock->method('name')->willReturn($name);
        $mock->method('description')->willReturn("Mock tool: {$name}");
        $mock->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $mock->method('isReadOnly')->willReturn(true);
        return $mock;
    }
}
