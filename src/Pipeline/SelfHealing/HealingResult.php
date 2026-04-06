<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\SelfHealing;

final class HealingResult
{
    public function __construct(
        public readonly bool $healed,
        public readonly int $attemptsUsed,
        public readonly array $diagnoses,
        public readonly array $plansAttempted,
        public readonly mixed $result = null,
        public readonly float $healingCost = 0.0,
        public readonly float $totalDurationMs = 0.0,
        public readonly string $summary = '',
    ) {}

    public function wasHealed(): bool
    {
        return $this->healed;
    }

    public function getCostBreakdown(): array
    {
        $diagnosisCost = 0.0;
        $retryCost = 0.0;

        foreach ($this->plansAttempted as $plan) {
            if ($plan instanceof HealingPlan) {
                $retryCost += $plan->estimatedAdditionalCost;
            }
        }

        return [
            'total' => round($this->healingCost, 4),
            'diagnosis' => round($this->healingCost - $retryCost, 4),
            'retries' => round($retryCost, 4),
            'attempts' => $this->attemptsUsed,
        ];
    }

    public function toArray(): array
    {
        return [
            'healed' => $this->healed,
            'attempts_used' => $this->attemptsUsed,
            'diagnoses' => array_map(
                fn($d) => $d instanceof Diagnosis ? $d->toArray() : $d,
                $this->diagnoses,
            ),
            'plans_attempted' => array_map(
                fn($p) => $p instanceof HealingPlan ? $p->toArray() : $p,
                $this->plansAttempted,
            ),
            'healing_cost' => round($this->healingCost, 4),
            'total_duration_ms' => round($this->totalDurationMs, 2),
            'summary' => $this->summary,
        ];
    }
}
