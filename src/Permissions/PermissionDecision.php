<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

class PermissionDecision
{
    public function __construct(
        public readonly PermissionBehavior $behavior,
        public readonly ?string $message = null,
        public readonly ?array $updatedInput = null,
        public readonly ?PermissionDecisionReason $decisionReason = null,
        public readonly array $suggestions = [],
    ) {}
    
    public static function allow(
        ?string $message = null,
        ?PermissionDecisionReason $reason = null
    ): self {
        return new self(
            behavior: PermissionBehavior::ALLOW,
            message: $message,
            decisionReason: $reason,
        );
    }
    
    public static function deny(
        ?string $message = null,
        ?PermissionDecisionReason $reason = null
    ): self {
        return new self(
            behavior: PermissionBehavior::DENY,
            message: $message,
            decisionReason: $reason,
        );
    }
    
    public static function ask(
        ?string $message = null,
        ?PermissionDecisionReason $reason = null,
        array $suggestions = []
    ): self {
        return new self(
            behavior: PermissionBehavior::ASK,
            message: $message,
            decisionReason: $reason,
            suggestions: $suggestions,
        );
    }
}

class PermissionDecisionReason
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $detail = null,
        public readonly ?PermissionRule $rule = null,
    ) {}
}

class PermissionUpdate
{
    public function __construct(
        public readonly string $label,
        public readonly ?PermissionRule $allowRule = null,
        public readonly ?PermissionRule $denyRule = null,
        public readonly ?PermissionMode $mode = null,
    ) {}
}