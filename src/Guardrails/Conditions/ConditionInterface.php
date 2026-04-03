<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Conditions;

use SuperAgent\Guardrails\Context\RuntimeContext;

interface ConditionInterface
{
    /**
     * Evaluate this condition against the given runtime context.
     */
    public function evaluate(RuntimeContext $context): bool;

    /**
     * Human-readable description for audit logs and debugging.
     */
    public function describe(): string;
}
