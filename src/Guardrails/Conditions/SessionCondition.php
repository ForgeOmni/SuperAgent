<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

class SessionCondition implements ConditionInterface
{
    public function __construct(
        private readonly string $field,
        private readonly string $operator,
        private readonly mixed $value,
    ) {}

    public function evaluate(RuntimeContext $context): bool
    {
        $actual = match ($this->field) {
            'cost_usd' => $context->sessionCostUsd,
            'budget_pct' => $context->budgetPct,
            'elapsed_ms' => $context->elapsedMs,
            'total_tokens' => $context->sessionTotalTokens,
            'input_tokens' => $context->sessionInputTokens,
            'output_tokens' => $context->sessionOutputTokens,
            'message_count' => $context->messageCount,
            'context_tokens' => $context->contextTokenCount,
            default => null,
        };

        if ($actual === null) {
            return false;
        }

        return Comparator::compare($actual, $this->operator, $this->value);
    }

    public function describe(): string
    {
        return "session.{$this->field} {$this->operator} {$this->value}";
    }

    /**
     * @return string[]
     */
    public static function supportedFields(): array
    {
        return [
            'cost_usd', 'budget_pct', 'elapsed_ms', 'total_tokens',
            'input_tokens', 'output_tokens', 'message_count', 'context_tokens',
        ];
    }
}
