<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class ToolInputCondition implements ConditionInterface
{
    public function __construct(
        private readonly string $field,
        private readonly string $operator,
        private readonly mixed $value,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        $actual = $context->toolInput[$this->field] ?? null;

        if ($actual === null) {
            return false;
        }

        return Comparator::compare($actual, $this->operator, $this->value);
    }

    public function describe(): string
    {
        $val = is_array($this->value) ? json_encode($this->value) : "'{$this->value}'";

        return "tool_input.{$this->field} {$this->operator} {$val}";
    }
}
