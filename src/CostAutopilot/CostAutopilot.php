<?php

declare(strict_types=1);

namespace SuperAgent\CostAutopilot;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Intelligent cost control autopilot for AI agent sessions.
 *
 * Monitors cumulative spending against budget thresholds and automatically
 * takes escalating actions to prevent budget overruns:
 *
 *   50% → warn          (log a warning, continue normally)
 *   70% → compact       (reduce context window to cut input tokens)
 *   80% → downgrade     (switch to a cheaper model tier)
 *   95% → halt          (stop the agent loop)
 *
 * The autopilot is stateful: it tracks which tiers have already been applied
 * so it doesn't re-trigger the same downgrade. Each evaluation returns an
 * immutable AutopilotDecision describing what action(s) to take.
 *
 * Usage:
 *   $autopilot = new CostAutopilot($config);
 *   $autopilot->setCurrentModel('claude-opus-4-20250514');
 *
 *   // After each provider call in the QueryEngine loop:
 *   $decision = $autopilot->evaluate($sessionCostUsd);
 *   if ($decision->hasDowngrade()) {
 *       $provider->setModel($decision->newModel);
 *   }
 *   if ($decision->shouldHalt()) {
 *       break;
 *   }
 */
class CostAutopilot
{
    private BudgetConfig $config;

    private LoggerInterface $logger;

    /** Current model the provider is using */
    private string $currentModel = '';

    /** Index of the current tier in the config's tier list (0 = most expensive) */
    private int $currentTierIndex = -1;

    /** Set of threshold percentages that have already fired (prevents re-triggering) */
    private array $firedThresholds = [];

    /** Persistent budget tracker for cross-session tracking */
    private ?BudgetTracker $budgetTracker = null;

    /** @var callable[] event listeners: event => [callable, ...] */
    private array $listeners = [];

    public function __construct(BudgetConfig $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Set the current model the provider is using.
     * This determines where we are in the tier hierarchy.
     */
    public function setCurrentModel(string $model): void
    {
        $this->currentModel = $model;
        $this->currentTierIndex = $this->findTierIndex($model);
    }

    /**
     * Get the current model.
     */
    public function getCurrentModel(): string
    {
        return $this->currentModel;
    }

    /**
     * Set the budget tracker for cross-session tracking.
     */
    public function setBudgetTracker(BudgetTracker $tracker): void
    {
        $this->budgetTracker = $tracker;
    }

    /**
     * Register an event listener.
     *
     * Events: autopilot.warn, autopilot.downgrade, autopilot.compact, autopilot.halt
     */
    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Evaluate the current budget state and return a decision.
     *
     * Should be called after each provider call with the cumulative session cost.
     */
    public function evaluate(float $sessionCostUsd): AutopilotDecision
    {
        $effectiveBudget = $this->getEffectiveBudget();

        if ($effectiveBudget <= 0) {
            return AutopilotDecision::noop(0, $sessionCostUsd);
        }

        // Record in persistent tracker
        $this->budgetTracker?->recordSpend($sessionCostUsd);

        $budgetUsedPct = ($sessionCostUsd / $effectiveBudget) * 100;
        $actions = [];
        $message = null;
        $newModel = null;
        $previousModel = null;
        $tierName = null;

        // Evaluate thresholds from highest to lowest
        foreach ($this->config->getThresholds() as $threshold) {
            if ($budgetUsedPct < $threshold->atPct) {
                continue;
            }

            // Skip if this threshold has already fired
            $thresholdKey = $threshold->atPct . ':' . $threshold->action->value;
            if (isset($this->firedThresholds[$thresholdKey])) {
                continue;
            }

            // Fire this threshold
            $this->firedThresholds[$thresholdKey] = true;
            $actions[] = $threshold->action;
            $message = $threshold->message;

            $this->logger->info("CostAutopilot: threshold {$threshold->atPct}% triggered", [
                'action' => $threshold->action->value,
                'budget_used_pct' => round($budgetUsedPct, 1),
                'session_cost' => $sessionCostUsd,
                'effective_budget' => $effectiveBudget,
            ]);

            // Handle model downgrade
            if ($threshold->action === CostAction::DOWNGRADE_MODEL) {
                $downgrade = $this->resolveDowngrade();
                if ($downgrade !== null) {
                    $newModel = $downgrade->model;
                    $previousModel = $this->currentModel;
                    $tierName = $downgrade->name;
                    $this->currentModel = $newModel;
                    $this->currentTierIndex = $this->findTierIndex($newModel);

                    $this->emit('autopilot.downgrade', [
                        'from' => $previousModel,
                        'to' => $newModel,
                        'tier' => $tierName,
                        'budget_used_pct' => $budgetUsedPct,
                    ]);

                    $message = "Downgrading model: {$previousModel} → {$newModel} ({$tierName} tier)";
                } else {
                    // Already at cheapest tier, escalate to warn
                    $actions = array_filter($actions, fn ($a) => $a !== CostAction::DOWNGRADE_MODEL);
                    $actions[] = CostAction::WARN;
                    $message = "Already at cheapest model tier, cannot downgrade further";
                }
            }

            if ($threshold->action === CostAction::WARN) {
                $this->emit('autopilot.warn', [
                    'budget_used_pct' => $budgetUsedPct,
                    'session_cost' => $sessionCostUsd,
                    'message' => $message,
                ]);
            }

            if ($threshold->action === CostAction::COMPACT_CONTEXT) {
                $this->emit('autopilot.compact', [
                    'budget_used_pct' => $budgetUsedPct,
                ]);
            }

            if ($threshold->action === CostAction::HALT) {
                $this->emit('autopilot.halt', [
                    'budget_used_pct' => $budgetUsedPct,
                    'session_cost' => $sessionCostUsd,
                ]);
            }

            // Only fire the highest applicable threshold per evaluation
            break;
        }

        if (empty($actions)) {
            return AutopilotDecision::noop($budgetUsedPct, $sessionCostUsd);
        }

        return new AutopilotDecision(
            actions: $actions,
            newModel: $newModel,
            previousModel: $previousModel,
            tierName: $tierName,
            budgetUsedPct: $budgetUsedPct,
            sessionCostUsd: $sessionCostUsd,
            message: $message,
        );
    }

    /**
     * Get the effective budget considering both session and monthly limits.
     */
    public function getEffectiveBudget(): float
    {
        $sessionBudget = $this->config->sessionBudgetUsd;
        $monthlyBudget = $this->config->monthlyBudgetUsd;

        // If both are set, use the more restrictive one
        if ($sessionBudget > 0 && $monthlyBudget > 0) {
            $remainingMonthly = $monthlyBudget - ($this->budgetTracker?->getMonthlySpend() ?? 0);

            return min($sessionBudget, max(0, $remainingMonthly));
        }

        if ($sessionBudget > 0) {
            return $sessionBudget;
        }

        if ($monthlyBudget > 0) {
            return max(0, $monthlyBudget - ($this->budgetTracker?->getMonthlySpend() ?? 0));
        }

        return 0;
    }

    /**
     * Get the budget configuration.
     */
    public function getConfig(): BudgetConfig
    {
        return $this->config;
    }

    /**
     * Reset fired thresholds (e.g., for a new session).
     */
    public function reset(): void
    {
        $this->firedThresholds = [];
        $this->currentTierIndex = $this->findTierIndex($this->currentModel);
    }

    /**
     * Get statistics about the autopilot state.
     *
     * @return array{current_model: string, current_tier: string|null, tiers_remaining: int, thresholds_fired: int}
     */
    public function getStatistics(): array
    {
        $tiers = $this->config->getTiers();
        $tiersRemaining = count($tiers) - $this->currentTierIndex - 1;

        return [
            'current_model' => $this->currentModel,
            'current_tier' => $this->currentTierIndex >= 0 && isset($tiers[$this->currentTierIndex])
                ? $tiers[$this->currentTierIndex]->name
                : null,
            'tiers_remaining' => max(0, $tiersRemaining),
            'thresholds_fired' => count($this->firedThresholds),
        ];
    }

    /**
     * Find the next cheaper tier to downgrade to.
     */
    private function resolveDowngrade(): ?ModelTier
    {
        $tiers = $this->config->getTiers();

        if (empty($tiers)) {
            return null;
        }

        // If current model isn't in the tier list, start from the second tier
        if ($this->currentTierIndex < 0) {
            return $tiers[1] ?? $tiers[0] ?? null;
        }

        $nextIndex = $this->currentTierIndex + 1;

        if ($nextIndex >= count($tiers)) {
            return null; // Already at cheapest tier
        }

        return $tiers[$nextIndex];
    }

    /**
     * Find the tier index for a given model name.
     */
    private function findTierIndex(string $model): int
    {
        foreach ($this->config->getTiers() as $index => $tier) {
            if ($tier->model === $model) {
                return $index;
            }

            // Fuzzy match: model starts with tier model name
            if (str_starts_with($model, $tier->model)) {
                return $index;
            }
        }

        return -1; // Not in tier list
    }

    /**
     * Emit an event to listeners.
     */
    private function emit(string $event, array $data): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($data);
            } catch (\Throwable $e) {
                $this->logger->warning("CostAutopilot event listener error: {$e->getMessage()}");
            }
        }
    }
}
