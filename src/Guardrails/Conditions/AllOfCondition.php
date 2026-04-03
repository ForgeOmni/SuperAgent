<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class AllOfCondition implements ConditionInterface
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
            if (!$condition->evaluate($context)) {
                return false;
            }
        }

        return !empty($this->conditions);
    }

    public function describe(): string
    {
        $parts = array_map(fn (ConditionInterface $c) => $c->describe(), $this->conditions);

        return 'ALL OF (' . implode(' AND ', $parts) . ')';
    }

    /**
     * @return ConditionInterface[]
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }
}
