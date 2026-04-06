<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\SelfHealing;

final class HealingPlan
{
    public const STRATEGY_MODIFY_PROMPT = 'modify_prompt';
    public const STRATEGY_CHANGE_MODEL = 'change_model';
    public const STRATEGY_ADJUST_TIMEOUT = 'adjust_timeout';
    public const STRATEGY_SIMPLIFY_TASK = 'simplify_task';
    public const STRATEGY_ADD_CONTEXT = 'add_context';
    public const STRATEGY_SKIP_AND_COMPENSATE = 'skip_and_compensate';
    public const STRATEGY_SPLIT_STEP = 'split_step';

    public function __construct(
        public readonly string $strategy,
        public readonly array $mutations,
        public readonly string $rationale,
        public readonly float $estimatedSuccessRate,
        public readonly float $estimatedAdditionalCost,
    ) {}

    /**
     * @return array{type: string, value: mixed}[]
     */
    public function getMutations(): array
    {
        return $this->mutations;
    }

    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy,
            'mutations' => $this->mutations,
            'rationale' => $this->rationale,
            'estimated_success_rate' => round($this->estimatedSuccessRate, 2),
            'estimated_additional_cost' => round($this->estimatedAdditionalCost, 4),
        ];
    }
}
