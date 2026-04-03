<?php

namespace SuperAgent\Tests\Unit\SkillDistillation;

use PHPUnit\Framework\TestCase;
use SuperAgent\SkillDistillation\DistillationEngine;
use SuperAgent\SkillDistillation\DistillationStore;
use SuperAgent\SkillDistillation\ExecutionTrace;
use SuperAgent\SkillDistillation\ToolCallRecord;

class DistillationEngineTest extends TestCase
{
    private DistillationStore $store;
    private DistillationEngine $engine;

    protected function setUp(): void
    {
        $this->store = new DistillationStore(null);
        $this->engine = new DistillationEngine($this->store, minSteps: 3, minCostUsd: 0.01);
    }

    private function makeTrace(int $steps = 5, string $model = 'claude-opus-4-20250514', float $cost = 0.50): ExecutionTrace
    {
        $toolCalls = [];
        for ($i = 0; $i < $steps; $i++) {
            $toolCalls[] = new ToolCallRecord(
                toolName: ['Read', 'Grep', 'Edit', 'Bash', 'Write'][$i % 5],
                toolInput: ['file_path' => "/src/file{$i}.php"],
                toolOutput: "Result {$i}",
            );
        }

        return new ExecutionTrace(
            originalPrompt: 'Fix the authentication bug in login flow',
            model: $model,
            toolCalls: $toolCalls,
            finalOutput: 'Fixed the bug',
            costUsd: $cost,
            inputTokens: 10000,
            outputTokens: 5000,
            turns: $steps,
            createdAt: '2026-04-03T10:00:00+00:00',
        );
    }

    // ── Distillation ───────────────────────────────────────────────

    public function test_distill_successful_trace(): void
    {
        $trace = $this->makeTrace(5);
        $skill = $this->engine->distill($trace);

        $this->assertNotNull($skill);
        $this->assertStringContainsString('fix-the-authentication', $skill->name);
        $this->assertSame('claude-opus-4-20250514', $skill->sourceModel);
        $this->assertSame('claude-sonnet-4-20250514', $skill->targetModel);
        $this->assertSame(5, $skill->sourceSteps);
        $this->assertGreaterThan(0, $skill->estimatedSavingsPct);
        $this->assertNotEmpty($skill->requiredTools);
        $this->assertStringContainsString('Step 1', $skill->template);
        $this->assertStringContainsString('---', $skill->template); // Has frontmatter
    }

    public function test_distill_with_custom_name(): void
    {
        $trace = $this->makeTrace(3);
        $skill = $this->engine->distill($trace, 'my-custom-skill');

        $this->assertNotNull($skill);
        $this->assertSame('my-custom-skill', $skill->name);
    }

    public function test_distill_saves_to_store(): void
    {
        $trace = $this->makeTrace(3);
        $skill = $this->engine->distill($trace, 'stored-skill');

        $this->assertNotNull($skill);
        $found = $this->store->get($skill->id);
        $this->assertNotNull($found);
        $this->assertSame('stored-skill', $found->name);
    }

    public function test_distill_skips_duplicate(): void
    {
        $trace = $this->makeTrace(3);
        $skill1 = $this->engine->distill($trace, 'unique-name');
        $skill2 = $this->engine->distill($trace, 'unique-name');

        $this->assertNotNull($skill1);
        $this->assertNull($skill2);
    }

    // ── Not Worth Distilling ───────────────────────────────────────

    public function test_skip_too_few_steps(): void
    {
        $trace = $this->makeTrace(2); // Below min_steps=3

        $this->assertFalse($this->engine->isWorthDistilling($trace));
        $this->assertNull($this->engine->distill($trace));
    }

    public function test_skip_too_cheap(): void
    {
        $trace = $this->makeTrace(5, cost: 0.001); // Below min_cost=0.01

        $this->assertFalse($this->engine->isWorthDistilling($trace));
    }

    public function test_skip_trace_with_errors(): void
    {
        $trace = new ExecutionTrace(
            originalPrompt: 'test',
            model: 'claude-opus-4-20250514',
            toolCalls: [
                new ToolCallRecord('Read', [], 'ok'),
                new ToolCallRecord('Edit', [], 'ok'),
                new ToolCallRecord('Bash', [], 'Error: command failed', isError: true),
            ],
            finalOutput: 'Failed',
            costUsd: 0.50,
            inputTokens: 1000,
            outputTokens: 500,
            turns: 3,
            createdAt: date('c'),
        );

        $this->assertFalse($this->engine->isWorthDistilling($trace));
    }

    // ── Model Downgrade Selection ──────────────────────────────────

    public function test_opus_downgrades_to_sonnet(): void
    {
        $trace = $this->makeTrace(3, 'claude-opus-4-20250514');
        $skill = $this->engine->distill($trace);

        $this->assertSame('claude-sonnet-4-20250514', $skill->targetModel);
    }

    public function test_sonnet_downgrades_to_haiku(): void
    {
        $trace = $this->makeTrace(3, 'claude-sonnet-4-20250514');
        $skill = $this->engine->distill($trace, 'sonnet-test');

        $this->assertSame('claude-haiku-4-5-20251001', $skill->targetModel);
    }

    public function test_gpt4o_downgrades_to_mini(): void
    {
        $trace = $this->makeTrace(3, 'gpt-4o');
        $skill = $this->engine->distill($trace, 'gpt-test');

        $this->assertSame('gpt-4o-mini', $skill->targetModel);
    }

    public function test_unknown_model_keeps_same(): void
    {
        $trace = $this->makeTrace(3, 'custom-local-model');
        $skill = $this->engine->distill($trace, 'custom-test');

        $this->assertSame('custom-local-model', $skill->targetModel);
    }

    // ── Savings Estimation ─────────────────────────────────────────

    public function test_opus_to_sonnet_savings(): void
    {
        $trace = $this->makeTrace(3, 'claude-opus-4-20250514');
        $skill = $this->engine->distill($trace, 'savings-test');

        // Opus=100, Sonnet=30 → 70% savings
        $this->assertSame(70.0, $skill->estimatedSavingsPct);
    }

    // ── Template Generation ────────────────────────────────────────

    public function test_template_contains_frontmatter(): void
    {
        $trace = $this->makeTrace(3);
        $skill = $this->engine->distill($trace, 'template-test');

        $this->assertStringContainsString('---', $skill->template);
        $this->assertStringContainsString('name: template-test', $skill->template);
        $this->assertStringContainsString('model:', $skill->template);
        $this->assertStringContainsString('category: distilled', $skill->template);
    }

    public function test_template_contains_steps(): void
    {
        $trace = $this->makeTrace(4);
        $skill = $this->engine->distill($trace, 'steps-test');

        $this->assertStringContainsString('Step 1', $skill->template);
        $this->assertStringContainsString('Step 2', $skill->template);
        $this->assertStringContainsString('Step 3', $skill->template);
        $this->assertStringContainsString('Step 4', $skill->template);
    }

    public function test_template_contains_tool_instructions(): void
    {
        $trace = new ExecutionTrace(
            originalPrompt: 'Read and edit a file',
            model: 'claude-opus-4-20250514',
            toolCalls: [
                new ToolCallRecord('Read', ['file_path' => '/src/App.php'], 'content'),
                new ToolCallRecord('Edit', ['file_path' => '/src/App.php'], 'edited'),
                new ToolCallRecord('Bash', ['command' => 'phpunit'], 'passed'),
            ],
            finalOutput: 'Done',
            costUsd: 0.50,
            inputTokens: 5000,
            outputTokens: 2000,
            turns: 3,
            createdAt: date('c'),
        );

        $skill = $this->engine->distill($trace, 'tool-test');
        $template = $skill->template;

        $this->assertStringContainsString('Read', $template);
        $this->assertStringContainsString('/src/App.php', $template);
        $this->assertStringContainsString('Edit', $template);
        $this->assertStringContainsString('phpunit', $template);
    }

    // ── Parameter Detection ────────────────────────────────────────

    public function test_detects_file_parameter(): void
    {
        $trace = new ExecutionTrace(
            originalPrompt: 'Fix file',
            model: 'claude-opus-4-20250514',
            toolCalls: [
                new ToolCallRecord('Read', ['file_path' => '/a.php'], 'c'),
                new ToolCallRecord('Edit', ['file_path' => '/a.php'], 'c'),
                new ToolCallRecord('Bash', ['command' => 'test'], 'c'),
            ],
            finalOutput: 'Done',
            costUsd: 0.50,
            inputTokens: 1000,
            outputTokens: 500,
            turns: 3,
            createdAt: date('c'),
        );

        $skill = $this->engine->distill($trace, 'param-test');
        $this->assertContains('target_file', $skill->parameters);
        $this->assertContains('command', $skill->parameters);
        $this->assertContains('task_description', $skill->parameters);
    }

    public function test_detects_search_parameter(): void
    {
        $trace = new ExecutionTrace(
            originalPrompt: 'Find pattern',
            model: 'claude-opus-4-20250514',
            toolCalls: [
                new ToolCallRecord('Grep', ['pattern' => 'TODO'], 'found'),
                new ToolCallRecord('Read', ['file_path' => '/a.php'], 'c'),
                new ToolCallRecord('Edit', ['file_path' => '/a.php'], 'c'),
            ],
            finalOutput: 'Done',
            costUsd: 0.50,
            inputTokens: 1000,
            outputTokens: 500,
            turns: 3,
            createdAt: date('c'),
        );

        $skill = $this->engine->distill($trace, 'search-param-test');
        $this->assertContains('search_pattern', $skill->parameters);
    }
}
