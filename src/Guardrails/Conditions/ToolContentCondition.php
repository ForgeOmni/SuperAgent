<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class ToolContentCondition implements ConditionInterface
{
    public function __construct(
        private readonly string $operator,
        private readonly string $value,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        if ($context->toolContent === null) {
            return false;
        }

        return Comparator::compare($context->toolContent, $this->operator, $this->value);
    }

    public function describe(): string
    {
        return "tool_content {$this->operator} '{$this->value}'";
    }
}
