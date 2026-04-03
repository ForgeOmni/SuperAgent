<?php

declare(strict_types=1);

namespace SuperAgent\AdaptiveFeedback;

/**
 * Result of promoting a correction pattern to a rule or memory.
 */
class PromotionResult
{
    public function __construct(
        public readonly string $patternId,
        public readonly string $type,
        public readonly string $description,
        public readonly string $content,
        public readonly CorrectionPattern $pattern,
    ) {}

    /**
     * Whether this was promoted to a guardrails rule.
     */
    public function isRule(): bool
    {
        return $this->type === 'rule';
    }

    /**
     * Whether this was promoted to a memory entry.
     */
    public function isMemory(): bool
    {
        return $this->type === 'memory';
    }
}
