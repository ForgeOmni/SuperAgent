<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

use SuperAgent\Guardrails\Rules\Rule;
use SuperAgent\Guardrails\Rules\RuleAction;
use SuperAgent\Hooks\HookResult;
use SuperAgent\Permissions\PermissionBehavior;
use SuperAgent\Permissions\PermissionDecision;
use SuperAgent\Permissions\PermissionDecisionReason;

class GuardrailsResult
{
    /**
     * @param Rule[] $allMatched All matched rules (for all_matching mode)
     */
    public function __construct(
        public readonly bool $matched,
        public readonly ?RuleAction $action = null,
        public readonly ?string $message = null,
        public readonly ?Rule $matchedRule = null,
        public readonly ?string $groupName = null,
        public readonly array $params = [],
        public readonly array $allMatched = [],
    ) {}

    public static function noMatch(): self
    {
        return new self(matched: false);
    }

    public static function fromRule(Rule $rule, string $groupName): self
    {
        return new self(
            matched: true,
            action: $rule->action,
            message: $rule->message,
            matchedRule: $rule,
            groupName: $groupName,
            params: $rule->params,
        );
    }

    /**
     * Convert to a PermissionDecision for integration with PermissionEngine.
     * Returns null for non-permission actions (warn, log, downgrade_model).
     */
    public function toPermissionDecision(): ?PermissionDecision
    {
        if (!$this->matched || $this->action === null) {
            return null;
        }

        $reason = new PermissionDecisionReason(
            'guardrail',
            $this->matchedRule?->name,
        );

        $message = $this->message ?? "Guardrail rule: {$this->matchedRule?->name}";

        return match ($this->action) {
            RuleAction::DENY => PermissionDecision::deny($message, $reason),
            RuleAction::ALLOW => PermissionDecision::allow($message, $reason),
            RuleAction::ASK => PermissionDecision::ask($message, $reason),
            RuleAction::PAUSE => PermissionDecision::deny(
                $message . ' (paused for ' . ($this->params['duration_seconds'] ?? '?') . 's)',
                $reason,
            ),
            RuleAction::RATE_LIMIT => PermissionDecision::deny($message, $reason),
            // Non-permission actions return null
            default => null,
        };
    }

    /**
     * Convert to a HookResult for integration with HookRegistry.
     */
    public function toHookResult(): HookResult
    {
        if (!$this->matched || $this->action === null) {
            return HookResult::continue();
        }

        return match ($this->action) {
            RuleAction::DENY, RuleAction::PAUSE, RuleAction::RATE_LIMIT => HookResult::deny(
                $this->message ?? "Blocked by guardrail: {$this->matchedRule?->name}",
            ),
            RuleAction::ALLOW => HookResult::allow(),
            RuleAction::ASK => HookResult::ask(
                $this->message ?? "Guardrail requires approval: {$this->matchedRule?->name}",
            ),
            default => HookResult::continue(),
        };
    }
}
