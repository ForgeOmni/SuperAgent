<?php

declare(strict_types=1);

namespace SuperAgent\CostAutopilot;

/**
 * Immutable decision from the CostAutopilot after evaluating budget state.
 */
class AutopilotDecision
{
    /**
     * @param CostAction[] $actions Actions to take (ordered by priority)
     * @param string|null $newModel Model to switch to (if DOWNGRADE_MODEL is in actions)
     * @param string|null $previousModel Model being replaced
     * @param string|null $tierName Name of the new tier
     * @param float $budgetUsedPct Percentage of budget consumed
     * @param float $sessionCostUsd Current session cost
     * @param string|null $message Human-readable explanation
     */
    public function __construct(
        public readonly array $actions,
        public readonly ?string $newModel = null,
        public readonly ?string $previousModel = null,
        public readonly ?string $tierName = null,
        public readonly float $budgetUsedPct = 0.0,
        public readonly float $sessionCostUsd = 0.0,
        public readonly ?string $message = null,
    ) {}

    /**
     * No action needed — budget is healthy.
     */
    public static function noop(float $budgetUsedPct, float $sessionCostUsd): self
    {
        return new self(
            actions: [],
            budgetUsedPct: $budgetUsedPct,
            sessionCostUsd: $sessionCostUsd,
        );
    }

    /**
     * Whether any action is required.
     */
    public function requiresAction(): bool
    {
        return !empty($this->actions);
    }

    /**
     * Whether a model downgrade is included.
     */
    public function hasDowngrade(): bool
    {
        return in_array(CostAction::DOWNGRADE_MODEL, $this->actions, true);
    }

    /**
     * Whether the agent should be halted.
     */
    public function shouldHalt(): bool
    {
        return in_array(CostAction::HALT, $this->actions, true);
    }

    /**
     * Whether context compaction is recommended.
     */
    public function shouldCompact(): bool
    {
        return in_array(CostAction::COMPACT_CONTEXT, $this->actions, true);
    }

    /**
     * Whether this is just a warning.
     */
    public function isWarning(): bool
    {
        return $this->actions === [CostAction::WARN];
    }
}
