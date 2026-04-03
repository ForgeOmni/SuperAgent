<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class ToolNameCondition implements ConditionInterface
{
    /**
     * @param string|string[] $name Exact name or list of names (any_of)
     */
    public function __construct(
        private readonly string|array $name,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        if (is_array($this->name)) {
            return in_array($context->toolName, $this->name, true);
        }

        return $context->toolName === $this->name;
    }

    public function describe(): string
    {
        if (is_array($this->name)) {
            return 'tool.name in [' . implode(', ', $this->name) . ']';
        }

        return "tool.name == '{$this->name}'";
    }
}
