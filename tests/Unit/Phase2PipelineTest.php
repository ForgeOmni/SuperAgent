<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Hooks\HookEvent;
use SuperAgent\Hooks\HookResult;
use SuperAgent\Prompt\SystemPromptBuilder;

class Phase2PipelineTest extends TestCase
{
    // === HookResult permission tests ===

    public function test_hook_result_allow(): void
    {
        $result = HookResult::allow(['key' => 'modified'], 'Hook approved');

        $this->assertTrue($result->continue);
        $this->assertEquals('allow', $result->permissionBehavior);
        $this->assertEquals('Hook approved', $result->permissionReason);
        $this->assertEquals(['key' => 'modified'], $result->updatedInput);
    }

    public function test_hook_result_deny(): void
    {
        $result = HookResult::deny('Security violation');

        $this->assertFalse($result->continue);
        $this->assertEquals('deny', $result->permissionBehavior);
        $this->assertEquals('Security violation', $result->permissionReason);
    }

    public function test_hook_result_ask(): void
    {
        $result = HookResult::ask('This tool needs approval');

        $this->assertTrue($result->continue);
        $this->assertEquals('ask', $result->permissionBehavior);
        $this->assertEquals('This tool needs approval', $result->permissionReason);
    }

    public function test_hook_result_merge_deny_wins(): void
    {
        $results = [
            HookResult::allow(reason: 'Hook 1 allows'),
            HookResult::deny('Hook 2 denies'),
            HookResult::allow(reason: 'Hook 3 allows'),
        ];

        $merged = HookResult::merge($results);

        $this->assertEquals('deny', $merged->permissionBehavior);
        $this->assertEquals('Hook 2 denies', $merged->permissionReason);
    }

    public function test_hook_result_merge_ask_wins_over_allow(): void
    {
        $results = [
            HookResult::allow(reason: 'Hook 1'),
            HookResult::ask('Hook 2 asks'),
        ];

        $merged = HookResult::merge($results);

        $this->assertEquals('ask', $merged->permissionBehavior);
    }

    public function test_hook_result_merge_preserves_updated_input(): void
    {
        $results = [
            HookResult::continue(updatedInput: ['a' => 1]),
            HookResult::continue(updatedInput: ['b' => 2]),
        ];

        $merged = HookResult::merge($results);

        $this->assertEquals(['a' => 1, 'b' => 2], $merged->updatedInput);
    }

    public function test_hook_result_prevent_continuation(): void
    {
        $result = new HookResult(
            continue: true,
            preventContinuation: true,
            stopReason: 'Post-tool hook stopped loop',
        );

        $this->assertTrue($result->preventContinuation);
    }

    public function test_hook_result_merge_prevent_continuation(): void
    {
        $results = [
            HookResult::continue(),
            new HookResult(continue: true, preventContinuation: true),
        ];

        $merged = HookResult::merge($results);
        $this->assertTrue($merged->preventContinuation);
    }

    // === SystemPromptBuilder tests ===

    public function test_builder_produces_default_sections(): void
    {
        $prompt = SystemPromptBuilder::create()->build();

        $this->assertStringContainsString('interactive AI agent', $prompt);
        $this->assertStringContainsString('System Rules', $prompt);
        $this->assertStringContainsString('Task Execution Philosophy', $prompt);
        $this->assertStringContainsString('Executing Actions with Care', $prompt);
        $this->assertStringContainsString('Tool Usage Rules', $prompt);
        $this->assertStringContainsString('Tone and Style', $prompt);
        $this->assertStringContainsString('Output Efficiency', $prompt);
        $this->assertStringContainsString(SystemPromptBuilder::CACHE_BOUNDARY, $prompt);
    }

    public function test_builder_with_tools_adds_agent_guidance(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withTools(['bash', 'read_file', 'agent'])
            ->build();

        $this->assertStringContainsString('sub-agents', $prompt);
    }

    public function test_builder_without_agent_tool_no_agent_guidance(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withTools(['bash', 'read_file'])
            ->build();

        $this->assertStringNotContainsString('sub-agents', $prompt);
    }

    public function test_builder_with_language(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withLanguage('中文')
            ->build();

        $this->assertStringContainsString('中文', $prompt);
    }

    public function test_builder_with_memory(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withMemory('User prefers TDD approach.')
            ->build();

        $this->assertStringContainsString('User prefers TDD approach.', $prompt);
    }

    public function test_builder_with_environment(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withEnvironment([
                'Platform' => 'darwin',
                'PHP Version' => '8.1',
            ])
            ->build();

        $this->assertStringContainsString('Platform: darwin', $prompt);
        $this->assertStringContainsString('PHP Version: 8.1', $prompt);
    }

    public function test_builder_with_custom_section(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withCustomSection('project_rules', 'Always use PSR-12 coding standard.')
            ->build();

        $this->assertStringContainsString('PSR-12', $prompt);
    }

    public function test_builder_without_section(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withoutSection('tone_style')
            ->build();

        $this->assertStringNotContainsString('Tone and Style', $prompt);
    }

    public function test_builder_replace_section(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->replaceSection('intro', 'You are a custom assistant.')
            ->build();

        $this->assertStringContainsString('custom assistant', $prompt);
        $this->assertStringNotContainsString('interactive AI agent', $prompt);
    }

    public function test_builder_cache_boundary_separates_static_and_dynamic(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withMemory('Some memory')
            ->build();

        $boundaryPos = strpos($prompt, SystemPromptBuilder::CACHE_BOUNDARY);
        $memoryPos = strpos($prompt, 'Some memory');
        $introPos = strpos($prompt, 'interactive AI agent');

        // Static sections before boundary, dynamic after
        $this->assertLessThan($boundaryPos, $introPos);
        $this->assertGreaterThan($boundaryPos, $memoryPos);
    }

    public function test_builder_build_array(): void
    {
        $sections = SystemPromptBuilder::create()
            ->withMemory('memory content')
            ->buildArray();

        $this->assertIsArray($sections);
        $this->assertContains(SystemPromptBuilder::CACHE_BOUNDARY, $sections);

        // Find boundary index
        $boundaryIndex = array_search(SystemPromptBuilder::CACHE_BOUNDARY, $sections);
        $this->assertNotFalse($boundaryIndex);

        // Static sections before boundary
        $this->assertGreaterThan(0, $boundaryIndex);

        // Dynamic sections after boundary
        $afterBoundary = array_slice($sections, $boundaryIndex + 1);
        $this->assertNotEmpty($afterBoundary);
    }

    public function test_builder_get_section_names(): void
    {
        $names = SystemPromptBuilder::create()->getSectionNames();

        $this->assertContains('intro', $names['static']);
        $this->assertContains('system_rules', $names['static']);
        $this->assertContains('doing_tasks', $names['static']);
        $this->assertContains('actions', $names['static']);
        $this->assertContains('tool_usage', $names['static']);
        $this->assertContains('tone_style', $names['static']);
        $this->assertContains('output_efficiency', $names['static']);
    }

    public function test_builder_null_language_and_memory_excluded(): void
    {
        $prompt = SystemPromptBuilder::create()
            ->withLanguage(null)
            ->withMemory(null)
            ->build();

        $this->assertStringNotContainsString('Always respond in', $prompt);
    }
}
