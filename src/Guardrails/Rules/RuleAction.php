<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Rules;

enum RuleAction: string
{
    case DENY = 'deny';
    case ALLOW = 'allow';
    case ASK = 'ask';
    case WARN = 'warn';
    case LOG = 'log';
    case PAUSE = 'pause';
    case RATE_LIMIT = 'rate_limit';
    case DOWNGRADE_MODEL = 'downgrade_model';

    /**
     * Whether this action maps to a permission decision that blocks execution.
     */
    public function isBlocking(): bool
    {
        return match ($this) {
            self::DENY, self::PAUSE, self::RATE_LIMIT => true,
            default => false,
        };
    }

    /**
     * Whether this action maps to a permission decision.
     */
    public function isPermissionAction(): bool
    {
        return match ($this) {
            self::DENY, self::ALLOW, self::ASK => true,
            default => false,
        };
    }
}
