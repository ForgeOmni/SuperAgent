<?php

namespace SuperAgent\Tests\Unit\SmartContext;

use PHPUnit\Framework\TestCase;
use SuperAgent\SmartContext\BudgetAllocation;
use SuperAgent\SmartContext\ContextStrategy;
use SuperAgent\SmartContext\SmartContextManager;
use SuperAgent\SmartContext\TaskComplexity;

class SmartContextTest extends TestCase
{
    // ── ContextStrategy ────────────────────────────────────────────

    public function test_strategy_ratios(): void
    {
        $this->assertSame(0.60, ContextStrategy::DEEP_THINKING->thinkingRatio());
        $this->assertSame(0.40, ContextStrategy::DEEP_THINKING->contextRatio());

        $this->assertSame(0.40, ContextStrategy::BALANCED->thinkingRatio());
        $this->assertSame(0.60, ContextStrategy::BALANCED->contextRatio());

        $this->assertSame(0.15, ContextStrategy::BROAD_CONTEXT->thinkingRatio());
        $this->assertSame(0.85, ContextStrategy::BROAD_CONTEXT->contextRatio());
    }

    public function test_strategy_compaction_keep_recent(): void
    {
        $this->assertSame(4, ContextStrategy::DEEP_THINKING->compactionKeepRecent());
        $this->assertSame(8, ContextStrategy::BALANCED->compactionKeepRecent());
        $this->assertSame(16, ContextStrategy::BROAD_CONTEXT->compactionKeepRecent());
    }

    // ── TaskComplexity ─────────────────────────────────────────────

    public function test_complex_task_detected(): void
    {
        $result = TaskComplexity::analyze(
            'Refactor the authentication module to use OAuth2. '
            . 'Investigate the current implementation, analyze the security implications, '
            . 'and design a migration path that handles backward compatibility.'
        );

        $this->assertSame(ContextStrategy::DEEP_THINKING, $result->strategy);
        $this->assertGreaterThanOrEqual(0.7, $result->score);
    }

    public function test_simple_task_detected(): void
    {
        $result = TaskComplexity::analyze('Show me the contents of config.php');

        $this->assertSame(ContextStrategy::BROAD_CONTEXT, $result->strategy);
        $this->assertLessThanOrEqual(0.35, $result->score);
    }

    public function test_balanced_task_detected(): void
    {
        $result = TaskComplexity::analyze('Add a new validation rule for email fields');

        $this->assertSame(ContextStrategy::BALANCED, $result->strategy);
        $this->assertGreaterThan(0.35, $result->score);
        $this->assertLessThan(0.7, $result->score);
    }

    public function test_short_question_is_simple(): void
    {
        $result = TaskComplexity::analyze('What is the database driver?');

        $this->assertLessThanOrEqual(0.4, $result->score);
    }

    public function test_long_prompt_increases_complexity(): void
    {
        $longPrompt = str_repeat('Implement a comprehensive solution for ', 100);
        $shortPrompt = 'Fix typo';

        $longResult = TaskComplexity::analyze($longPrompt);
        $shortResult = TaskComplexity::analyze($shortPrompt);

        $this->assertGreaterThan($shortResult->score, $longResult->score);
    }

    public function test_multi_step_increases_complexity(): void
    {
        $result = TaskComplexity::analyze(
            'First read the file, then refactor the class, after that run the tests, '
            . 'and finally update the documentation.'
        );

        $this->assertContains('multi_step:4', $result->signals);
    }

    public function test_code_in_prompt_increases_complexity(): void
    {
        $result = TaskComplexity::analyze(
            "Fix this function:\n```php\nfunction broken() { return null; }\n```"
        );

        $this->assertContains('has_code', $result->signals);
    }

    public function test_describe(): void
    {
        $result = TaskComplexity::analyze('Read the config file');
        $desc = $result->describe();

        $this->assertStringContainsString('%', $desc);
        $this->assertStringContainsString($result->strategy->value, $desc);
    }

    // ── BudgetAllocation ───────────────────────────────────────────

    public function test_allocation_percentages(): void
    {
        $alloc = new BudgetAllocation(
            strategy: ContextStrategy::BALANCED,
            thinkingBudgetTokens: 40_000,
            contextBudgetTokens: 60_000,
            compactionKeepRecent: 8,
            complexityScore: 0.5,
            totalBudgetTokens: 100_000,
        );

        $this->assertSame(40.0, $alloc->thinkingPct());
        $this->assertSame(60.0, $alloc->contextPct());
    }

    public function test_allocation_describe(): void
    {
        $alloc = new BudgetAllocation(
            strategy: ContextStrategy::DEEP_THINKING,
            thinkingBudgetTokens: 60_000,
            contextBudgetTokens: 40_000,
            compactionKeepRecent: 4,
            complexityScore: 0.8,
            totalBudgetTokens: 100_000,
        );

        $desc = $alloc->describe();
        $this->assertStringContainsString('deep_thinking', $desc);
        $this->assertStringContainsString('60000', $desc);
    }

    public function test_allocation_to_array(): void
    {
        $alloc = new BudgetAllocation(
            strategy: ContextStrategy::BALANCED,
            thinkingBudgetTokens: 40_000,
            contextBudgetTokens: 60_000,
            compactionKeepRecent: 8,
            complexityScore: 0.5,
            totalBudgetTokens: 100_000,
            signals: ['medium_prompt'],
        );

        $arr = $alloc->toArray();
        $this->assertSame('balanced', $arr['strategy']);
        $this->assertSame(40_000, $arr['thinking_budget_tokens']);
        $this->assertSame(60_000, $arr['context_budget_tokens']);
        $this->assertContains('medium_prompt', $arr['signals']);
    }

    // ── SmartContextManager ────────────────────────────────────────

    public function test_allocate_complex_task(): void
    {
        $manager = new SmartContextManager(totalBudgetTokens: 100_000);

        $alloc = $manager->allocate(
            'Refactor the entire authentication module, investigate security issues, '
            . 'analyze the architecture, and design a new system.'
        );

        $this->assertSame(ContextStrategy::DEEP_THINKING, $alloc->strategy);
        $this->assertSame(60_000, $alloc->thinkingBudgetTokens);
        $this->assertSame(40_000, $alloc->contextBudgetTokens);
        $this->assertSame(4, $alloc->compactionKeepRecent);
    }

    public function test_allocate_simple_task(): void
    {
        $manager = new SmartContextManager(totalBudgetTokens: 100_000);

        $alloc = $manager->allocate('List all files in src/');

        $this->assertSame(ContextStrategy::BROAD_CONTEXT, $alloc->strategy);
        $this->assertSame(15_000, $alloc->thinkingBudgetTokens);
        $this->assertSame(85_000, $alloc->contextBudgetTokens);
        $this->assertSame(16, $alloc->compactionKeepRecent);
    }

    public function test_force_strategy_override(): void
    {
        $manager = new SmartContextManager(totalBudgetTokens: 100_000);
        $manager->setForceStrategy(ContextStrategy::DEEP_THINKING);

        // Even a simple prompt gets deep_thinking when forced
        $alloc = $manager->allocate('Show me file.txt');

        $this->assertSame(ContextStrategy::DEEP_THINKING, $alloc->strategy);
        $this->assertSame(60_000, $alloc->thinkingBudgetTokens);
    }

    public function test_force_strategy_string(): void
    {
        $manager = new SmartContextManager(totalBudgetTokens: 100_000);
        $manager->setForceStrategy('broad_context');

        $alloc = $manager->allocate('Refactor everything');

        $this->assertSame(ContextStrategy::BROAD_CONTEXT, $alloc->strategy);
    }

    public function test_force_strategy_null_resets(): void
    {
        $manager = new SmartContextManager(totalBudgetTokens: 100_000);
        $manager->setForceStrategy(ContextStrategy::DEEP_THINKING);
        $manager->setForceStrategy(null); // Reset

        // Back to auto-detection
        $alloc = $manager->allocate('List files');
        $this->assertSame(ContextStrategy::BROAD_CONTEXT, $alloc->strategy);
    }

    public function test_min_thinking_budget_enforced(): void
    {
        $manager = new SmartContextManager(
            totalBudgetTokens: 10_000,
            minThinkingBudget: 5_000,
        );

        $alloc = $manager->allocate('List files');

        // Even broad_context (15% = 1500) gets clamped to min 5000
        $this->assertGreaterThanOrEqual(5_000, $alloc->thinkingBudgetTokens);
    }

    public function test_max_thinking_budget_enforced(): void
    {
        $manager = new SmartContextManager(
            totalBudgetTokens: 500_000,
            maxThinkingBudget: 128_000,
        );
        $manager->setForceStrategy(ContextStrategy::DEEP_THINKING);

        $alloc = $manager->allocate('Complex task');

        // 60% of 500K = 300K, clamped to max 128K
        $this->assertSame(128_000, $alloc->thinkingBudgetTokens);
    }

    public function test_is_enabled(): void
    {
        $enabled = new SmartContextManager(configEnabled: true);
        $this->assertTrue($enabled->isEnabled());

        $disabled = new SmartContextManager(configEnabled: false);
        $this->assertFalse($disabled->isEnabled());

        // Force strategy makes it enabled regardless of config
        $disabled->setForceStrategy(ContextStrategy::BALANCED);
        $this->assertTrue($disabled->isEnabled());
    }

    public function test_total_budget(): void
    {
        $manager = new SmartContextManager(totalBudgetTokens: 200_000);
        $this->assertSame(200_000, $manager->getTotalBudgetTokens());
    }

    public function test_allocation_sums_to_total(): void
    {
        $manager = new SmartContextManager(totalBudgetTokens: 100_000);

        foreach (ContextStrategy::cases() as $strategy) {
            $manager->setForceStrategy($strategy);
            $alloc = $manager->allocate('test');

            $this->assertSame(
                100_000,
                $alloc->thinkingBudgetTokens + $alloc->contextBudgetTokens,
                "Strategy {$strategy->value}: budgets should sum to total"
            );
        }
    }
}
