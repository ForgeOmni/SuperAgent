<?php

declare(strict_types=1);

namespace SuperAgent\CostAutopilot;

/**
 * A single threshold rule: when budget usage reaches at_pct%, take the given action.
 */
class ThresholdRule
{
    public function __construct(
        public readonly float $atPct,
        public readonly CostAction $action,
        public readonly ?string $message = null,
    ) {}
}
