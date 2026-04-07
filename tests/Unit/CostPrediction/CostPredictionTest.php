<?php

namespace SuperAgent\Tests\Unit\CostPrediction;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostPrediction\TaskAnalyzer;
use SuperAgent\CostPrediction\TaskProfile;
use SuperAgent\CostPrediction\CostEstimate;

class CostPredictionTest extends TestCase
{
    private TaskAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new TaskAnalyzer();
    }

    // ── TaskAnalyzer: type detection ─────────────────────────────

    public function test_detects_code_generation(): void
    {
        $profile = $this->analyzer->analyze('Write a new function to parse CSV files');
        $this->assertEquals(TaskProfile::TYPE_CODE_GENERATION, $profile->taskType);
    }

    public function test_detects_refactoring(): void
    {
        $profile = $this->analyzer->analyze('Refactor the authentication module to use dependency injection');
        $this->assertEquals(TaskProfile::TYPE_REFACTORING, $profile->taskType);
    }

    public function test_detects_debugging(): void
    {
        $profile = $this->analyzer->analyze('Fix the bug where login fails with empty password');
        $this->assertEquals(TaskProfile::TYPE_DEBUGGING, $profile->taskType);
    }

    public function test_detects_testing(): void
    {
        $profile = $this->analyzer->analyze('Write unit tests for the UserService class');
        $this->assertEquals(TaskProfile::TYPE_TESTING, $profile->taskType);
    }

    public function test_detects_analysis(): void
    {
        $profile = $this->analyzer->analyze('Explain how the caching layer works');
        $this->assertEquals(TaskProfile::TYPE_ANALYSIS, $profile->taskType);
    }

    public function test_detects_chat_for_generic_prompts(): void
    {
        $profile = $this->analyzer->analyze('Hello, how are you?');
        $this->assertEquals(TaskProfile::TYPE_CHAT, $profile->taskType);
    }

    // ── TaskAnalyzer: complexity detection ────────────────────────

    public function test_detects_simple_complexity(): void
    {
        $profile = $this->analyzer->analyze('Fix a typo in README');
        $this->assertEquals(TaskProfile::COMPLEXITY_SIMPLE, $profile->complexity);
    }

    public function test_detects_complex_complexity(): void
    {
        $profile = $this->analyzer->analyze('Refactor all database queries to use the new ORM across multiple files');
        $this->assertContains($profile->complexity, [
            TaskProfile::COMPLEXITY_COMPLEX,
            TaskProfile::COMPLEXITY_VERY_COMPLEX,
        ]);
    }

    public function test_detects_very_complex_from_keyword(): void
    {
        $profile = $this->analyzer->analyze('Migrate the entire codebase from PHP 7 to PHP 8');
        $this->assertEquals(TaskProfile::COMPLEXITY_VERY_COMPLEX, $profile->complexity);
    }

    public function test_long_prompt_increases_complexity(): void
    {
        $longPrompt = str_repeat('Implement a comprehensive feature that requires many changes. ', 50);
        $profile = $this->analyzer->analyze($longPrompt);
        $this->assertNotEquals(TaskProfile::COMPLEXITY_SIMPLE, $profile->complexity);
    }

    // ── TaskAnalyzer: tool and estimate detection ─────────────────

    public function test_detects_likely_tools(): void
    {
        $profile = $this->analyzer->analyze('Search for all usages of deprecated function and edit them');
        $this->assertContains('grep', $profile->likelyTools);
        $this->assertContains('edit', $profile->likelyTools);
    }

    public function test_estimates_more_turns_for_complex_tasks(): void
    {
        $simple = $this->analyzer->analyze('Fix a simple typo');
        $complex = $this->analyzer->analyze('Migrate the entire codebase to a new architecture');

        $this->assertGreaterThan($simple->estimatedTurns, $complex->estimatedTurns);
    }

    public function test_estimates_more_tokens_for_complex_tasks(): void
    {
        $simple = $this->analyzer->analyze('Rename a variable');
        $complex = $this->analyzer->analyze('Refactor all services to use dependency injection across multiple files');

        $this->assertGreaterThan($simple->estimatedInputTokens, $complex->estimatedInputTokens);
        $this->assertGreaterThan($simple->estimatedOutputTokens, $complex->estimatedOutputTokens);
    }

    // ── TaskProfile ──────────────────────────────────────────────

    public function test_profile_complexity_multiplier(): void
    {
        $simple = new TaskProfile('chat', TaskProfile::COMPLEXITY_SIMPLE, 1, [], 1, 100, 50, 'h');
        $complex = new TaskProfile('chat', TaskProfile::COMPLEXITY_COMPLEX, 1, [], 1, 100, 50, 'h');

        $this->assertEquals(1.0, $simple->getComplexityMultiplier());
        $this->assertEquals(4.0, $complex->getComplexityMultiplier());
    }

    public function test_profile_to_array(): void
    {
        $profile = new TaskProfile(
            taskType: 'debugging',
            complexity: 'moderate',
            estimatedToolCalls: 5,
            likelyTools: ['read', 'grep'],
            estimatedTurns: 3,
            estimatedInputTokens: 5000,
            estimatedOutputTokens: 2000,
            taskHash: 'abc123',
        );

        $arr = $profile->toArray();
        $this->assertEquals('debugging', $arr['task_type']);
        $this->assertEquals('moderate', $arr['complexity']);
        $this->assertEquals(5, $arr['estimated_tool_calls']);
        $this->assertCount(2, $arr['likely_tools']);
        $this->assertArrayHasKey('complexity_multiplier', $arr);
    }

    // ── CostEstimate ─────────────────────────────────────────────

    public function test_estimate_format(): void
    {
        $est = new CostEstimate(
            model: 'claude-opus',
            estimatedCost: 0.05,
            lowerBound: 0.02,
            upperBound: 0.10,
            estimatedTokens: 10000,
            estimatedTurns: 3,
            estimatedDurationSeconds: 30.0,
            confidence: 0.8,
            basis: 'heuristic',
        );

        $formatted = $est->format();
        $this->assertStringContainsString('$0.0500', $formatted);
        $this->assertStringContainsString('heuristic', $formatted);
        $this->assertStringContainsString('80%', $formatted);
    }

    public function test_estimate_budget_check(): void
    {
        $est = new CostEstimate('opus', 0.05, 0.02, 0.10, 10000, 3, 30.0, 0.8, 'h');

        $this->assertTrue($est->isWithinBudget(0.50));
        $this->assertTrue($est->isWithinBudget(0.10));
        $this->assertFalse($est->isWithinBudget(0.09));
    }

    public function test_estimate_with_different_model(): void
    {
        $opus = new CostEstimate('opus', 1.0, 0.5, 1.5, 10000, 5, 60.0, 0.9, 'h');
        $sonnet = $opus->withModel('sonnet');

        $this->assertEquals('sonnet', $sonnet->model);
        $this->assertLessThan($opus->estimatedCost, $sonnet->estimatedCost);
        $this->assertLessThan($opus->confidence, $sonnet->confidence);
    }

    public function test_estimate_to_array(): void
    {
        $est = new CostEstimate('opus', 0.1, 0.05, 0.2, 5000, 3, 20.0, 0.75, 'historical');
        $arr = $est->toArray();

        $this->assertEquals('opus', $arr['model']);
        $this->assertEquals(0.75, $arr['confidence']);
        $this->assertEquals('historical', $arr['basis']);
        $this->assertArrayHasKey('estimated_tokens', $arr);
    }
}
