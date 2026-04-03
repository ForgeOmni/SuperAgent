<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class AgentCondition implements ConditionInterface
{
    public function __construct(
        private readonly string $field,
        private readonly string $operator,
        private readonly mixed $value,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        $actual = match ($this->field) {
            'turn_count' => $context->turnCount,
            'max_turns' => $context->maxTurns,
            'model' => $context->modelName,
            'session_id' => $context->sessionId,
            'continuation_count' => $context->continuationCount,
            default => null,
        };

        if ($actual === null) {
            return false;
        }

        return Comparator::compare($actual, $this->operator, $this->value);
    }

    public function describe(): string
    {
        $val = is_string($this->value) ? "'{$this->value}'" : $this->value;

        return "agent.{$this->field} {$this->operator} {$val}";
    }

    /**
     * @return string[]
     */
    public static function supportedFields(): array
    {
        return ['turn_count', 'max_turns', 'model', 'session_id', 'continuation_count'];
    }
}
