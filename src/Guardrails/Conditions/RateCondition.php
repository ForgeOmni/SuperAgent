<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class RateCondition implements ConditionInterface
{
    public function __construct(
        private readonly int $windowSeconds,
        private readonly int $maxCalls,
        private readonly ?string $toolFilter = null,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        if ($context->rateTracker === null) {
            return false;
        }

        $key = $this->toolFilter ?? $context->toolName;

        return $context->rateTracker->exceedsRate($key, $this->windowSeconds, $this->maxCalls);
    }

    public function describe(): string
    {
        $tool = $this->toolFilter ?? '*';

        return "rate({$tool}) >= {$this->maxCalls} in {$this->windowSeconds}s";
    }
}
