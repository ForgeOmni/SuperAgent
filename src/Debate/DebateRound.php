<?php

declare(strict_types=1);

namespace SuperAgent\Debate;

final class DebateRound
{
    public function __construct(
        public readonly int $roundNumber,
        public readonly string $proposerArgument,
        public readonly string $criticResponse,
        public readonly ?string $proposerRebuttal = null,
        public readonly float $roundCost = 0.0,
        public readonly float $durationMs = 0.0,
    ) {}

    public function getSummary(): string
    {
        $summary = "Round {$this->roundNumber}:\n";
        $summary .= "  Proposer: " . mb_substr($this->proposerArgument, 0, 200) . "\n";
        $summary .= "  Critic: " . mb_substr($this->criticResponse, 0, 200) . "\n";
        if ($this->proposerRebuttal !== null) {
            $summary .= "  Rebuttal: " . mb_substr($this->proposerRebuttal, 0, 200) . "\n";
        }
        return $summary;
    }

    public function toArray(): array
    {
        return [
            'round_number' => $this->roundNumber,
            'proposer_argument' => $this->proposerArgument,
            'critic_response' => $this->criticResponse,
            'proposer_rebuttal' => $this->proposerRebuttal,
            'round_cost' => $this->roundCost,
            'duration_ms' => $this->durationMs,
        ];
    }
}
