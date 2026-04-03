<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Context;

use SuperAgent\Messages\Usage;

/**
 * Collects runtime state from the QueryEngine loop and builds
 * immutable RuntimeContext snapshots for guardrail evaluation.
 */
class RuntimeContextCollector
{
    private float $sessionCostUsd = 0.0;
    private float $lastCallCostUsd = 0.0;
    private int $sessionInputTokens = 0;
    private int $sessionOutputTokens = 0;
    private int $turnCount = 0;
    private int $turnOutputTokens = 0;
    private float $startedAt;
    private RateTracker $rateTracker;

    public function __construct(
        private readonly string $modelName,
        private readonly string $sessionId,
        private readonly int $maxTurns,
        private readonly string $cwd,
        private readonly float $maxBudgetUsd = 0.0,
    ) {
        $this->startedAt = microtime(true);
        $this->rateTracker = new RateTracker();
    }

    /**
     * Record token usage and cost after a provider call.
     */
    public function recordUsage(?Usage $usage, float $costUsd): void
    {
        $this->lastCallCostUsd = $costUsd;
        $this->sessionCostUsd += $costUsd;

        if ($usage !== null) {
            $this->sessionInputTokens += $usage->inputTokens ?? 0;
            $this->sessionOutputTokens += $usage->outputTokens ?? 0;
            $this->turnOutputTokens += $usage->outputTokens ?? 0;
        }
    }

    /**
     * Record the start of a new turn.
     */
    public function recordTurn(): void
    {
        $this->turnCount++;
    }

    /**
     * Build an immutable context snapshot for a specific tool call.
     */
    public function buildContext(
        string $toolName,
        array $toolInput,
        ?string $toolContent,
        int $messageCount = 0,
        int $contextTokenCount = 0,
    ): RuntimeContext {
        $sessionTotal = $this->sessionInputTokens + $this->sessionOutputTokens;
        $elapsed = (microtime(true) - $this->startedAt) * 1000;
        $budgetPct = $this->maxBudgetUsd > 0
            ? ($this->sessionCostUsd / $this->maxBudgetUsd) * 100
            : 0.0;

        return new RuntimeContext(
            toolName: $toolName,
            toolInput: $toolInput,
            toolContent: $toolContent,
            sessionCostUsd: $this->sessionCostUsd,
            callCostUsd: $this->lastCallCostUsd,
            sessionInputTokens: $this->sessionInputTokens,
            sessionOutputTokens: $this->sessionOutputTokens,
            sessionTotalTokens: $sessionTotal,
            budgetPct: $budgetPct,
            continuationCount: 0,
            turnCount: $this->turnCount,
            maxTurns: $this->maxTurns,
            modelName: $this->modelName,
            sessionId: $this->sessionId,
            messageCount: $messageCount,
            contextTokenCount: $contextTokenCount,
            elapsedMs: $elapsed,
            cwd: $this->cwd,
            rateTracker: $this->rateTracker,
        );
    }

    /**
     * Reset for a new query (keeps rate tracker).
     */
    public function reset(): void
    {
        $this->sessionCostUsd = 0.0;
        $this->lastCallCostUsd = 0.0;
        $this->sessionInputTokens = 0;
        $this->sessionOutputTokens = 0;
        $this->turnCount = 0;
        $this->turnOutputTokens = 0;
        $this->startedAt = microtime(true);
    }

    public function getRateTracker(): RateTracker
    {
        return $this->rateTracker;
    }
}
