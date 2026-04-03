<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

use SuperAgent\Messages\Usage;

/**
 * Result of a single pipeline step execution.
 *
 * Carries both the step output and execution metrics (cost, token usage)
 * so that parallel step aggregation can track total resource consumption.
 */
class StepResult
{
    public function __construct(
        public readonly string $stepName,
        public readonly StepStatus $status,
        public readonly mixed $output = null,
        public readonly ?string $error = null,
        public readonly float $durationMs = 0,
        public readonly array $metadata = [],
        public readonly float $totalCostUsd = 0.0,
        public readonly ?Usage $tokenUsage = null,
        public readonly int $turns = 0,
        /** @var StepResult[] Sub-step results for parallel/loop steps */
        public readonly array $subResults = [],
    ) {}

    public static function success(
        string $stepName,
        mixed $output = null,
        float $durationMs = 0,
        array $metadata = [],
        float $totalCostUsd = 0.0,
        ?Usage $tokenUsage = null,
        int $turns = 0,
        array $subResults = [],
    ): self {
        return new self(
            stepName: $stepName,
            status: StepStatus::COMPLETED,
            output: $output,
            durationMs: $durationMs,
            metadata: $metadata,
            totalCostUsd: $totalCostUsd,
            tokenUsage: $tokenUsage,
            turns: $turns,
            subResults: $subResults,
        );
    }

    public static function failure(
        string $stepName,
        string $error,
        float $durationMs = 0,
        array $metadata = [],
        float $totalCostUsd = 0.0,
        ?Usage $tokenUsage = null,
        int $turns = 0,
        array $subResults = [],
    ): self {
        return new self(
            stepName: $stepName,
            status: StepStatus::FAILED,
            error: $error,
            durationMs: $durationMs,
            metadata: $metadata,
            totalCostUsd: $totalCostUsd,
            tokenUsage: $tokenUsage,
            turns: $turns,
            subResults: $subResults,
        );
    }

    public static function skipped(string $stepName, ?string $reason = null): self
    {
        return new self(
            stepName: $stepName,
            status: StepStatus::SKIPPED,
            error: $reason,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === StepStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === StepStatus::FAILED;
    }

    /**
     * Get the aggregate cost of this step, including all sub-steps.
     */
    public function getAggregateCostUsd(): float
    {
        $cost = $this->totalCostUsd;
        foreach ($this->subResults as $sub) {
            $cost += $sub->getAggregateCostUsd();
        }

        return $cost;
    }

    /**
     * Get the aggregate token usage of this step, including all sub-steps.
     */
    public function getAggregateTokenUsage(): Usage
    {
        $input = $this->tokenUsage?->inputTokens ?? 0;
        $output = $this->tokenUsage?->outputTokens ?? 0;

        foreach ($this->subResults as $sub) {
            $subUsage = $sub->getAggregateTokenUsage();
            $input += $subUsage->inputTokens;
            $output += $subUsage->outputTokens;
        }

        return new Usage($input, $output);
    }

    /**
     * Get the total number of LLM turns, including all sub-steps.
     */
    public function getAggregateTurns(): int
    {
        $total = $this->turns;
        foreach ($this->subResults as $sub) {
            $total += $sub->getAggregateTurns();
        }

        return $total;
    }
}
