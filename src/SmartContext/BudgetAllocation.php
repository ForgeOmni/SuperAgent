<?php

declare(strict_types=1);

namespace SuperAgent\SmartContext;

/**
 * Immutable result of the budget allocation decision.
 */
class BudgetAllocation
{
    public function __construct(
        public readonly ContextStrategy $strategy,
        public readonly int $thinkingBudgetTokens,
        public readonly int $contextBudgetTokens,
        public readonly int $compactionKeepRecent,
        public readonly float $complexityScore,
        public readonly int $totalBudgetTokens,
        public readonly array $signals = [],
    ) {}

    /**
     * Get thinking budget as a percentage.
     */
    public function thinkingPct(): float
    {
        return $this->totalBudgetTokens > 0
            ? round(($this->thinkingBudgetTokens / $this->totalBudgetTokens) * 100, 1)
            : 0.0;
    }

    /**
     * Get context budget as a percentage.
     */
    public function contextPct(): float
    {
        return $this->totalBudgetTokens > 0
            ? round(($this->contextBudgetTokens / $this->totalBudgetTokens) * 100, 1)
            : 0.0;
    }

    /**
     * Human-readable summary.
     */
    public function describe(): string
    {
        return "{$this->strategy->value}: thinking={$this->thinkingBudgetTokens} "
            . "({$this->thinkingPct()}%), context={$this->contextBudgetTokens} "
            . "({$this->contextPct()}%), keep_recent={$this->compactionKeepRecent}";
    }

    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy->value,
            'thinking_budget_tokens' => $this->thinkingBudgetTokens,
            'context_budget_tokens' => $this->contextBudgetTokens,
            'compaction_keep_recent' => $this->compactionKeepRecent,
            'complexity_score' => $this->complexityScore,
            'total_budget_tokens' => $this->totalBudgetTokens,
            'thinking_pct' => $this->thinkingPct(),
            'context_pct' => $this->contextPct(),
            'signals' => $this->signals,
        ];
    }
}
