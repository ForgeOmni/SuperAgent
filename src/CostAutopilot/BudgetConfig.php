<?php

declare(strict_types=1);

namespace SuperAgent\CostAutopilot;

use InvalidArgumentException;

/**
 * Configuration for the CostAutopilot budget and escalation thresholds.
 *
 * Defines budget limits (session and/or monthly) and the threshold percentages
 * at which escalation actions trigger. Thresholds are evaluated in order
 * (highest first), so the most severe action triggers at the highest usage.
 *
 * Example config array:
 *   [
 *       'session_budget_usd' => 5.00,
 *       'monthly_budget_usd' => 100.00,
 *       'thresholds' => [
 *           ['at_pct' => 50, 'action' => 'warn'],
 *           ['at_pct' => 70, 'action' => 'compact_context'],
 *           ['at_pct' => 80, 'action' => 'downgrade_model'],
 *           ['at_pct' => 95, 'action' => 'halt'],
 *       ],
 *       'tiers' => [...],  // Optional: custom model tiers
 *   ]
 */
class BudgetConfig
{
    /** @var ThresholdRule[] sorted by at_pct descending */
    private array $thresholds = [];

    /** @var ModelTier[] sorted by priority descending (most expensive first) */
    private array $tiers = [];

    private function __construct(
        public readonly float $sessionBudgetUsd,
        public readonly float $monthlyBudgetUsd,
    ) {}

    /**
     * Create from a configuration array.
     */
    public static function fromArray(array $config): self
    {
        $instance = new self(
            sessionBudgetUsd: (float) ($config['session_budget_usd'] ?? 0.0),
            monthlyBudgetUsd: (float) ($config['monthly_budget_usd'] ?? 0.0),
        );

        // Parse thresholds
        $thresholds = $config['thresholds'] ?? self::defaultThresholds();
        foreach ($thresholds as $t) {
            $action = CostAction::tryFrom($t['action'] ?? '');
            if ($action === null) {
                throw new InvalidArgumentException(
                    "Invalid threshold action: '{$t['action']}'. "
                    . 'Valid: ' . implode(', ', array_column(CostAction::cases(), 'value'))
                );
            }

            $instance->thresholds[] = new ThresholdRule(
                atPct: (float) ($t['at_pct'] ?? 0),
                action: $action,
                message: $t['message'] ?? null,
            );
        }

        // Sort thresholds by percentage descending (highest first for evaluation)
        usort($instance->thresholds, fn (ThresholdRule $a, ThresholdRule $b) => $b->atPct <=> $a->atPct);

        // Parse model tiers
        if (isset($config['tiers'])) {
            foreach ($config['tiers'] as $tierData) {
                $instance->tiers[] = new ModelTier(
                    name: $tierData['name'],
                    model: $tierData['model'],
                    costPerMillionInput: (float) ($tierData['input_cost'] ?? 0),
                    costPerMillionOutput: (float) ($tierData['output_cost'] ?? 0),
                    priority: (int) ($tierData['priority'] ?? 0),
                );
            }
        }

        // Sort tiers by priority descending (most expensive first)
        usort($instance->tiers, fn (ModelTier $a, ModelTier $b) => $b->priority <=> $a->priority);

        return $instance;
    }

    /**
     * Get the effective budget for evaluation (session budget takes precedence).
     */
    public function getEffectiveBudget(): float
    {
        if ($this->sessionBudgetUsd > 0) {
            return $this->sessionBudgetUsd;
        }

        return $this->monthlyBudgetUsd;
    }

    /**
     * Whether any budget is configured.
     */
    public function hasBudget(): bool
    {
        return $this->sessionBudgetUsd > 0 || $this->monthlyBudgetUsd > 0;
    }

    /**
     * @return ThresholdRule[]
     */
    public function getThresholds(): array
    {
        return $this->thresholds;
    }

    /**
     * @return ModelTier[]
     */
    public function getTiers(): array
    {
        return $this->tiers;
    }

    /**
     * Set model tiers (used when auto-detecting from provider).
     *
     * @param ModelTier[] $tiers
     */
    public function setTiers(array $tiers): void
    {
        $this->tiers = $tiers;
        usort($this->tiers, fn (ModelTier $a, ModelTier $b) => $b->priority <=> $a->priority);
    }

    /**
     * Validate the configuration.
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];

        if (!$this->hasBudget()) {
            $errors[] = 'At least one of session_budget_usd or monthly_budget_usd must be > 0';
        }

        if (empty($this->thresholds)) {
            $errors[] = 'At least one threshold is required';
        }

        foreach ($this->thresholds as $t) {
            if ($t->atPct < 0 || $t->atPct > 100) {
                $errors[] = "Threshold at_pct must be 0-100, got: {$t->atPct}";
            }
        }

        // Check that downgrade_model threshold exists only if tiers are defined
        $hasDowngrade = false;
        foreach ($this->thresholds as $t) {
            if ($t->action === CostAction::DOWNGRADE_MODEL) {
                $hasDowngrade = true;
            }
        }
        if ($hasDowngrade && empty($this->tiers)) {
            $errors[] = "downgrade_model threshold requires model tiers to be configured";
        }

        return $errors;
    }

    /**
     * Default thresholds when none are specified.
     */
    private static function defaultThresholds(): array
    {
        return [
            ['at_pct' => 50, 'action' => 'warn', 'message' => 'Budget 50% consumed'],
            ['at_pct' => 70, 'action' => 'compact_context', 'message' => 'Budget 70% consumed — compacting context'],
            ['at_pct' => 80, 'action' => 'downgrade_model', 'message' => 'Budget 80% consumed — downgrading model'],
            ['at_pct' => 95, 'action' => 'halt', 'message' => 'Budget 95% consumed — halting agent'],
        ];
    }
}
