<?php

declare(strict_types=1);

namespace SuperAgent\TokenBudget;

/**
 * Token budget continuation logic ported from Claude Code.
 *
 * Decides whether the agent should continue or stop based on:
 *  - Remaining token budget (stop at 90% consumption)
 *  - Diminishing returns detection (two consecutive low-delta turns after 3+ continuations)
 *
 * This replaces the fixed maxTurns approach with a dynamic, budget-aware strategy.
 */
class TokenBudgetTracker
{
    /** Stop at 90% of budget consumed */
    private const COMPLETION_THRESHOLD = 0.9;

    /** Delta threshold for diminishing returns (tokens) */
    private const DIMINISHING_THRESHOLD = 500;

    private int $continuationCount = 0;
    private int $lastDeltaTokens = 0;
    private int $lastGlobalTurnTokens = 0;
    private float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * Reset the tracker for a new query.
     */
    public function reset(): void
    {
        $this->continuationCount = 0;
        $this->lastDeltaTokens = 0;
        $this->lastGlobalTurnTokens = 0;
        $this->startedAt = microtime(true);
    }

    /**
     * Check whether the agent should continue running based on token budget.
     *
     * @param int|null $budget   Total token budget (null = no budget, stop immediately)
     * @param int      $globalTurnTokens  Total tokens consumed so far in this turn
     * @param bool     $isSubAgent  Whether this is a sub-agent (sub-agents always stop)
     */
    public function check(
        ?int $budget,
        int $globalTurnTokens,
        bool $isSubAgent = false,
    ): TokenBudgetDecision {
        // Sub-agents or no budget: always stop
        if ($isSubAgent || $budget === null || $budget <= 0) {
            return TokenBudgetDecision::stop(null);
        }

        $turnTokens = $globalTurnTokens;
        $pct = $budget > 0 ? (int) round(($turnTokens / $budget) * 100) : 0;
        $deltaSinceLastCheck = $globalTurnTokens - $this->lastGlobalTurnTokens;

        // Diminishing returns: 3+ continuations AND both current and previous
        // deltas are below threshold
        $isDiminishing = $this->continuationCount >= 3
            && $deltaSinceLastCheck < self::DIMINISHING_THRESHOLD
            && $this->lastDeltaTokens < self::DIMINISHING_THRESHOLD;

        // Continue if not diminishing and under completion threshold
        if (!$isDiminishing && $turnTokens < $budget * self::COMPLETION_THRESHOLD) {
            $this->continuationCount++;
            $this->lastDeltaTokens = $deltaSinceLastCheck;
            $this->lastGlobalTurnTokens = $globalTurnTokens;

            return TokenBudgetDecision::continue(
                nudgeMessage: $this->getBudgetContinuationMessage($pct, $turnTokens, $budget),
                continuationCount: $this->continuationCount,
                pct: $pct,
                turnTokens: $turnTokens,
                budget: $budget,
            );
        }

        // Stop with completion event metadata
        if ($isDiminishing || $this->continuationCount > 0) {
            return TokenBudgetDecision::stop(
                new TokenBudgetCompletionEvent(
                    continuationCount: $this->continuationCount,
                    pct: $pct,
                    turnTokens: $turnTokens,
                    budget: $budget,
                    diminishingReturns: $isDiminishing,
                    durationMs: (int) ((microtime(true) - $this->startedAt) * 1000),
                ),
            );
        }

        // Default stop (no budget tracking was active)
        return TokenBudgetDecision::stop(null);
    }

    public function getContinuationCount(): int
    {
        return $this->continuationCount;
    }

    private function getBudgetContinuationMessage(int $pct, int $turnTokens, int $budget): string
    {
        return sprintf(
            'Token budget: %d%% used (%d / %d tokens). Continue working on the task.',
            $pct,
            $turnTokens,
            $budget,
        );
    }
}

/**
 * Result of a token budget check.
 */
class TokenBudgetDecision
{
    private function __construct(
        public readonly string $action, // 'continue' or 'stop'
        public readonly ?string $nudgeMessage = null,
        public readonly ?TokenBudgetCompletionEvent $completionEvent = null,
        public readonly int $continuationCount = 0,
        public readonly int $pct = 0,
        public readonly int $turnTokens = 0,
        public readonly int $budget = 0,
    ) {}

    public static function continue(
        string $nudgeMessage,
        int $continuationCount,
        int $pct,
        int $turnTokens,
        int $budget,
    ): self {
        return new self(
            action: 'continue',
            nudgeMessage: $nudgeMessage,
            continuationCount: $continuationCount,
            pct: $pct,
            turnTokens: $turnTokens,
            budget: $budget,
        );
    }

    public static function stop(?TokenBudgetCompletionEvent $completionEvent): self
    {
        return new self(
            action: 'stop',
            completionEvent: $completionEvent,
        );
    }

    public function shouldContinue(): bool
    {
        return $this->action === 'continue';
    }

    public function shouldStop(): bool
    {
        return $this->action === 'stop';
    }
}

/**
 * Telemetry/debugging info emitted when the budget tracker decides to stop.
 */
class TokenBudgetCompletionEvent
{
    public function __construct(
        public readonly int $continuationCount,
        public readonly int $pct,
        public readonly int $turnTokens,
        public readonly int $budget,
        public readonly bool $diminishingReturns,
        public readonly int $durationMs,
    ) {}

    public function toArray(): array
    {
        return [
            'continuation_count' => $this->continuationCount,
            'pct' => $this->pct,
            'turn_tokens' => $this->turnTokens,
            'budget' => $this->budget,
            'diminishing_returns' => $this->diminishingReturns,
            'duration_ms' => $this->durationMs,
        ];
    }
}
