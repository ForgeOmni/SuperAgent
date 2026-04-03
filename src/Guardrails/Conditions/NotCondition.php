<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class NotCondition implements ConditionInterface
{
    public function __construct(
        private readonly ConditionInterface $condition,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        return !$this->condition->evaluate($context);
    }

    public function describe(): string
    {
        return 'NOT (' . $this->condition->describe() . ')';
    }

    public function getCondition(): ConditionInterface
    {
        return $this->condition;
    }
}
