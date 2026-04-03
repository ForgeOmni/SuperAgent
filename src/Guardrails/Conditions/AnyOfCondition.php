<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class AnyOfCondition implements ConditionInterface
{
    /**
     * @param ConditionInterface[] $conditions
     */
    public function __construct(
        private readonly array $conditions,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        foreach ($this->conditions as $condition) {
            if ($condition->evaluate($context)) {
                return true;
            }
        }

        return false;
    }

    public function describe(): string
    {
        $parts = array_map(fn (ConditionInterface $c) => $c->describe(), $this->conditions);

        return 'ANY OF (' . implode(' OR ', $parts) . ')';
    }

    /**
     * @return ConditionInterface[]
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }
}
