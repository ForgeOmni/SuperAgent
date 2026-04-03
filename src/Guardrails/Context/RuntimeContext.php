<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Context;

/**
 * Immutable snapshot of all runtime state available for guardrail condition evaluation.
 */
final class RuntimeContext
{
    public function __construct(
        // Tool info
        public readonly string $toolName,
        public readonly array $toolInput,
        public readonly ?string $toolContent,

        // Session cost
        public readonly float $sessionCostUsd = 0.0,
        public readonly float $callCostUsd = 0.0,

        // Token counts
        public readonly int $sessionInputTokens = 0,
        public readonly int $sessionOutputTokens = 0,
        public readonly int $sessionTotalTokens = 0,

        // Budget
        public readonly float $budgetPct = 0.0,
        public readonly int $continuationCount = 0,

        // Agent state
        public readonly int $turnCount = 0,
        public readonly int $maxTurns = 50,
        public readonly string $modelName = '',
        public readonly string $sessionId = '',

        // Context window
        public readonly int $messageCount = 0,
        public readonly int $contextTokenCount = 0,

        // Timing
        public readonly float $elapsedMs = 0.0,

        // Working directory
        public readonly string $cwd = '',

        // Rate tracker (shared mutable reference)
        public readonly ?RateTracker $rateTracker = null,
    ) {}
}
