<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

/**
 * Result of task routing: which provider/model to use and why.
 */
class TaskRouteResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $tier,
        public readonly string $taskType,
        public readonly string $complexity,
        public readonly string $reason,
        public readonly float $estimatedCostMultiplier = 1.0,
    ) {}

    /**
     * Convert to an AgentProviderConfig for pipeline integration.
     */
    public function toProviderConfig(): AgentProviderConfig
    {
        return AgentProviderConfig::crossProvider($this->provider, [
            'model' => $this->model,
        ]);
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'tier' => $this->tier,
            'task_type' => $this->taskType,
            'complexity' => $this->complexity,
            'reason' => $this->reason,
            'estimated_cost_multiplier' => $this->estimatedCostMultiplier,
        ];
    }
}
