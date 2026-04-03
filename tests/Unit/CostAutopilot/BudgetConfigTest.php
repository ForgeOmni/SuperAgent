<?php

namespace SuperAgent\Tests\Unit\CostAutopilot;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\CostAction;
use SuperAgent\CostAutopilot\ModelTier;

class BudgetConfigTest extends TestCase
{
    public function test_from_array_with_session_budget(): void
    {
        $config = BudgetConfig::fromArray([
            'session_budget_usd' => 5.00,
        ]);

        $this->assertSame(5.0, $config->sessionBudgetUsd);
        $this->assertSame(0.0, $config->monthlyBudgetUsd);
        $this->assertTrue($config->hasBudget());
        $this->assertSame(5.0, $config->getEffectiveBudget());
    }

    public function test_from_array_with_monthly_budget(): void
    {
        $config = BudgetConfig::fromArray([
            'monthly_budget_usd' => 100.00,
        ]);

        $this->assertSame(0.0, $config->sessionBudgetUsd);
        $this->assertSame(100.0, $config->monthlyBudgetUsd);
        $this->assertTrue($config->hasBudget());
    }

    public function test_no_budget_configured(): void
    {
        $config = BudgetConfig::fromArray([]);

        $this->assertFalse($config->hasBudget());
        $this->assertSame(0.0, $config->getEffectiveBudget());
    }

    public function test_default_thresholds(): void
    {
        $config = BudgetConfig::fromArray([
            'session_budget_usd' => 10.0,
        ]);

        $thresholds = $config->getThresholds();
        $this->assertCount(4, $thresholds);

        // Sorted descending by at_pct
        $this->assertSame(95.0, $thresholds[0]->atPct);
        $this->assertSame(CostAction::HALT, $thresholds[0]->action);
        $this->assertSame(80.0, $thresholds[1]->atPct);
        $this->assertSame(CostAction::DOWNGRADE_MODEL, $thresholds[1]->action);
        $this->assertSame(70.0, $thresholds[2]->atPct);
        $this->assertSame(CostAction::COMPACT_CONTEXT, $thresholds[2]->action);
        $this->assertSame(50.0, $thresholds[3]->atPct);
        $this->assertSame(CostAction::WARN, $thresholds[3]->action);
    }

    public function test_custom_thresholds(): void
    {
        $config = BudgetConfig::fromArray([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 60, 'action' => 'warn'],
                ['at_pct' => 90, 'action' => 'halt'],
            ],
        ]);

        $thresholds = $config->getThresholds();
        $this->assertCount(2, $thresholds);
        $this->assertSame(90.0, $thresholds[0]->atPct);
        $this->assertSame(60.0, $thresholds[1]->atPct);
    }

    public function test_invalid_threshold_action(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid threshold action");

        BudgetConfig::fromArray([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 50, 'action' => 'explode'],
            ],
        ]);
    }

    public function test_custom_tiers(): void
    {
        $config = BudgetConfig::fromArray([
            'session_budget_usd' => 10.0,
            'tiers' => [
                ['name' => 'fast', 'model' => 'model-a', 'input_cost' => 10.0, 'output_cost' => 30.0, 'priority' => 30],
                ['name' => 'cheap', 'model' => 'model-b', 'input_cost' => 1.0, 'output_cost' => 3.0, 'priority' => 10],
            ],
        ]);

        $tiers = $config->getTiers();
        $this->assertCount(2, $tiers);
        // Sorted by priority descending
        $this->assertSame('fast', $tiers[0]->name);
        $this->assertSame('cheap', $tiers[1]->name);
    }

    public function test_set_tiers(): void
    {
        $config = BudgetConfig::fromArray(['session_budget_usd' => 10.0]);
        $this->assertEmpty($config->getTiers());

        $config->setTiers(ModelTier::anthropicTiers());
        $this->assertCount(3, $config->getTiers());
    }

    public function test_validate_no_budget(): void
    {
        $config = BudgetConfig::fromArray([]);
        $errors = $config->validate();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('budget', $errors[0]);
    }

    public function test_validate_downgrade_without_tiers(): void
    {
        $config = BudgetConfig::fromArray([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 80, 'action' => 'downgrade_model'],
            ],
        ]);

        $errors = $config->validate();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('tiers', $errors[0]);
    }

    public function test_validate_valid_config(): void
    {
        $config = BudgetConfig::fromArray([
            'session_budget_usd' => 10.0,
            'thresholds' => [
                ['at_pct' => 50, 'action' => 'warn'],
                ['at_pct' => 95, 'action' => 'halt'],
            ],
        ]);

        $this->assertEmpty($config->validate());
    }

    public function test_session_budget_takes_precedence(): void
    {
        $config = BudgetConfig::fromArray([
            'session_budget_usd' => 5.0,
            'monthly_budget_usd' => 100.0,
        ]);

        // Session budget is always the effective budget when set
        $this->assertSame(5.0, $config->getEffectiveBudget());
    }
}
