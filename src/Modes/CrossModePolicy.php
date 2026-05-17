<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

/**
 * Decision rules for cross-mode orchestration. Held inside a
 * `ModeContext` and consulted at every recursion / escalation
 * decision point.
 *
 * Defaults are conservative: 4 levels deep, no budget cap, escalate
 * after 3 in-a-row reviewer rejections by jumping to smart mode. A
 * host that wants strict bounds tightens these at boot.
 */
final class CrossModePolicy
{
    public const ESCALATE_TO_NONE  = '';
    public const ESCALATE_TO_AUTO  = 'auto';
    public const ESCALATE_TO_SMART = 'smart';
    public const ESCALATE_TO_SQUAD = 'squad';

    public function __construct(
        public readonly int    $maxDepth              = 4,
        public readonly ?float $budgetCapUsd          = null,
        public readonly bool   $detectCycles          = true,
        public readonly bool   $autoEscalateOnFailure = true,
        public readonly int    $escalateAfterRetries  = 3,
        public readonly string $escalateTo            = self::ESCALATE_TO_SMART,
        public readonly ?float $downgradeBelowScore   = null,
        public readonly ?string $downgradeTo          = null,
    ) {}

    /**
     * Whether the given mode_stack contains a cycle (the same mode
     * appearing twice in a row indicates the router is bouncing).
     *
     * @param list<string> $stack
     */
    public function hasCycle(array $stack): bool
    {
        if (!$this->detectCycles) return false;
        $n = count($stack);
        if ($n < 2) return false;
        return $stack[$n - 1] === $stack[$n - 2];
    }
}
