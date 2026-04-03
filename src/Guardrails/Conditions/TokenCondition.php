<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class TokenCondition implements ConditionInterface
{
    public function __construct(
        private readonly string $field,
        private readonly string $operator,
        private readonly mixed $value,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        $actual = match ($this->field) {
            'total_session' => $context->sessionTotalTokens,
            'input_session' => $context->sessionInputTokens,
            'output_session' => $context->sessionOutputTokens,
            'output_current' => $context->turnOutputTokens ?? 0,
            default => null,
        };

        if ($actual === null) {
            return false;
        }

        return Comparator::compare($actual, $this->operator, $this->value);
    }

    public function describe(): string
    {
        return "token.{$this->field} {$this->operator} {$this->value}";
    }

    /**
     * @return string[]
     */
    public static function supportedFields(): array
    {
        return ['total_session', 'input_session', 'output_session', 'output_current'];
    }
}
