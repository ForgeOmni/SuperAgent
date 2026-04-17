<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent\ForkContext;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Prompt\SystemPromptBuilder;
use SuperAgent\Providers\AnthropicProvider;

class Phase3Test extends TestCase
{
    // === Fork Context ===

    public function test_fork_context_builds_child_message(): void
    {
        $fork = new ForkContext(
            parentMessages: [],
            parentSystemPrompt: 'Parent system prompt',
        );

        $childMessage = $fork->buildChildMessage('Investigate the auth module');

        $this->assertStringContainsString('forked worker process', $childMessage);
        $this->assertStringContainsString('Investigate the auth module', $childMessage);
        $this->assertStringContainsString(ForkContext::FORK_DIRECTIVE_PREFIX, $childMessage);
        $this->assertStringContainsString(ForkContext::FORK_BOILERPLATE_TAG, $childMessage);
    }

    public function test_fork_context_builds_forked_messages(): void
    {
        $parentMessages = [
            new UserMessage('Hello'),
        ];

        $fork = new ForkContext(
            parentMessages: $parentMessages,
            parentSystemPrompt: 'System prompt',
            parentToolNames: ['bash', 'read_file'],
        );

        $forkedMessages = $fork->buildForkedMessages('Check the tests');

        // Should include parent messages + new directive
        $this->assertCount(2, $forkedMessages);
        $this->assertInstanceOf(UserMessage::class, $forkedMessages[1]);
    }

    public function test_fork_context_detects_fork_child(): void
    {
        $fork = new ForkContext([], '');
        $childMessage = $fork->buildChildMessage('test');

        $messages = [new UserMessage($childMessage)];

        $this->assertTrue(ForkContext::isInForkChild($messages));
    }

    public function test_fork_context_not_fork_child_for_normal_messages(): void
    {
        $messages = [new UserMessage('Normal user message')];

        $this->assertFalse(ForkContext::isInForkChild($messages));
    }

    public function test_fork_context_preserves_parent_system_prompt(): void
    {
        $parentPrompt = 'Exact byte-identical parent system prompt for cache sharing';

        $fork = new ForkContext(
            parentMessages: [],
            parentSystemPrompt: $parentPrompt,
        );

        $this->assertEquals($parentPrompt, $fork->parentSystemPrompt);
    }

    public function test_spawn_config_is_fork(): void
    {
        $config = new \SuperAgent\Swarm\AgentSpawnConfig(
            name: 'fork-agent',
            prompt: 'test',
            forkContext: new ForkContext([], 'parent prompt'),
        );

        $this->assertTrue($config->isFork());
    }

    public function test_spawn_config_is_not_fork(): void
    {
        $config = new \SuperAgent\Swarm\AgentSpawnConfig(
            name: 'normal-agent',
            prompt: 'test',
        );

        $this->assertFalse($config->isFork());
    }

    // === Prompt Cache Boundary ===

    public function test_system_prompt_builder_includes_cache_boundary(): void
    {
        $prompt = SystemPromptBuilder::create()->build();

        $this->assertStringContainsString(SystemPromptBuilder::CACHE_BOUNDARY, $prompt);
    }

    public function test_cache_boundary_separates_static_from_dynamic(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withMemory('User prefers dark mode')
            ->build();

        $boundary = SystemPromptBuilder::CACHE_BOUNDARY;
        $parts = explode($boundary, $prompt);

        $this->assertCount(2, $parts);

        $staticPart = $parts[0];
        $dynamicPart = $parts[1];

        // Static part has default sections
        $this->assertStringContainsString('interactive AI agent', $staticPart);
        $this->assertStringContainsString('System Rules', $staticPart);

        // Dynamic part has memory
        $this->assertStringContainsString('dark mode', $dynamicPart);
    }

    public function test_anthropic_provider_formats_system_prompt_without_caching(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test']);

        // Use reflection to test the protected method
        $method = new \ReflectionMethod($provider, 'formatSystemPrompt');
        $method->setAccessible(true);

        $prompt = "Static part\n\n" . SystemPromptBuilder::CACHE_BOUNDARY . "\n\nDynamic part";

        // Without caching: returns plain string
        $result = $method->invoke($provider, $prompt, false);
        $this->assertIsString($result);
        $this->assertEquals($prompt, $result);
    }

    public function test_anthropic_provider_formats_system_prompt_with_caching(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test']);

        $method = new \ReflectionMethod($provider, 'formatSystemPrompt');
        $method->setAccessible(true);

        $prompt = "Static part\n\n" . SystemPromptBuilder::CACHE_BOUNDARY . "\n\nDynamic part";

        // With caching: returns block array
        $result = $method->invoke($provider, $prompt, true);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        // First block is static with cache_control
        $this->assertEquals('text', $result[0]['type']);
        $this->assertStringContainsString('Static part', $result[0]['text']);
        $this->assertArrayHasKey('cache_control', $result[0]);
        $this->assertEquals('ephemeral', $result[0]['cache_control']['type']);

        // Second block is dynamic without cache_control
        $this->assertEquals('text', $result[1]['type']);
        $this->assertStringContainsString('Dynamic part', $result[1]['text']);
        $this->assertArrayNotHasKey('cache_control', $result[1]);
    }

    public function test_anthropic_provider_no_boundary_returns_string(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test']);

        $method = new \ReflectionMethod($provider, 'formatSystemPrompt');
        $method->setAccessible(true);

        $prompt = "Simple prompt without boundary";

        $result = $method->invoke($provider, $prompt, true);
        $this->assertIsString($result);
    }

    // === MCP Instructions Injection ===

    public function test_system_prompt_builder_with_mcp_instructions(): void
    {
        // Can't easily test with real MCPManager (needs connections),
        // but we can test the custom section approach
        $prompt = SystemPromptBuilder::create()
            ->withCustomSection('mcp_instructions',
                "# MCP Server Instructions\n\n## github\nUse search_repos to find repositories."
            )
            ->build();

        $this->assertStringContainsString('MCP Server Instructions', $prompt);
        $this->assertStringContainsString('search_repos', $prompt);

        // MCP instructions should be in the dynamic part (after boundary)
        $boundary = SystemPromptBuilder::CACHE_BOUNDARY;
        $parts = explode($boundary, $prompt);
        $dynamicPart = $parts[1] ?? '';
        $this->assertStringContainsString('MCP Server Instructions', $dynamicPart);
    }

    public function test_build_array_preserves_section_separation(): void
    {
        $sections = SystemPromptBuilder::create()
            ->withMemory('memory content')
            ->withCustomSection('mcp_instructions', 'MCP instructions here')
            ->buildArray();

        $boundaryIndex = array_search(SystemPromptBuilder::CACHE_BOUNDARY, $sections);

        // Count sections before and after boundary
        $beforeBoundary = array_slice($sections, 0, $boundaryIndex);
        $afterBoundary = array_slice($sections, $boundaryIndex + 1);

        // Static sections come before
        $this->assertGreaterThanOrEqual(5, count($beforeBoundary)); // intro, rules, tasks, actions, tools, tone, output

        // Dynamic sections come after
        $this->assertGreaterThanOrEqual(2, count($afterBoundary)); // memory + mcp
    }
}
