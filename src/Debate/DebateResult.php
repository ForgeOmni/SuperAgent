<?php

declare(strict_types=1);

namespace SuperAgent\Debate;

final class DebateResult
{
    public function __construct(
        public readonly string $type,
        public readonly string $topic,
        public readonly array $rounds,
        public readonly string $finalVerdict,
        public readonly string $recommendation,
        public readonly float $totalCost,
        public readonly float $totalDurationMs,
        public readonly array $agentContributions,
        public readonly int $totalTurns,
    ) {}

    /**
     * @return DebateRound[]
     */
    public function getRounds(): array
    {
        return $this->rounds;
    }

    public function getVerdict(): string
    {
        return $this->finalVerdict;
    }

    public function getCostBreakdown(): array
    {
        return [
            'total' => round($this->totalCost, 4),
            'per_round' => $this->totalCost / max(1, count($this->rounds)),
            'agents' => $this->agentContributions,
        ];
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'topic' => $this->topic,
            'rounds' => array_map(
                fn($r) => $r instanceof DebateRound ? $r->toArray() : $r,
                $this->rounds,
            ),
            'final_verdict' => $this->finalVerdict,
            'recommendation' => $this->recommendation,
            'total_cost' => round($this->totalCost, 4),
            'total_duration_ms' => round($this->totalDurationMs, 2),
            'total_turns' => $this->totalTurns,
            'agent_contributions' => $this->agentContributions,
        ];
    }
}
