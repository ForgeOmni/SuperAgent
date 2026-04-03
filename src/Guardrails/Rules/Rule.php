<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Rules;

use SuperAgent\Guardrails\Conditions\ConditionInterface;
use SuperAgent\Guardrails\Context\RuntimeContext;

class Rule
{
    public function __construct(
        public readonly string $name,
        public readonly ConditionInterface $condition,
        public readonly RuleAction $action,
        public readonly ?string $message = null,
        public readonly ?string $description = null,
        public readonly array $params = [],
    ) {}

    /**
     * Evaluate this rule against the given context.
     */
    public function matches(RuntimeContext $context): bool
    {
        return $this->condition->evaluate($context);
    }

    /**
     * Get a human-readable summary of this rule for debugging.
     */
    public function describe(): string
    {
        return "[{$this->name}] IF {$this->condition->describe()} THEN {$this->action->value}";
    }
}
