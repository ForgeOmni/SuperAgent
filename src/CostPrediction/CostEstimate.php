<?php

declare(strict_types=1);

namespace SuperAgent\CostPrediction;

final class CostEstimate
{
    public function __construct(
        public readonly string $model,
        public readonly float $estimatedCost,
        public readonly float $lowerBound,
        public readonly float $upperBound,
        public readonly int $estimatedTokens,
        public readonly int $estimatedTurns,
        public readonly float $estimatedDurationSeconds,
        public readonly float $confidence,
        public readonly string $basis,
        public readonly array $breakdown = [],
    ) {}

    public function format(): string
    {
        $cost = number_format($this->estimatedCost, 4);
        $lower = number_format($this->lowerBound, 4);
        $upper = number_format($this->upperBound, 4);
        $conf = (int) ($this->confidence * 100);
        $tokens = number_format($this->estimatedTokens);

        return "Estimated: \${$cost} (range: \${$lower}-\${$upper}), ~{$tokens} tokens, ~{$this->estimatedTurns} turns, confidence: {$conf}% [{$this->basis}]";
    }

    public function isWithinBudget(float $budget): bool
    {
        return $this->upperBound <= $budget;
    }

    /**
     * Re-estimate for a different model using pricing ratios.
     */
    public function withModel(string $newModel): self
    {
        $ratio = $this->getModelPriceRatio($this->model, $newModel);

        return new self(
            model: $newModel,
            estimatedCost: $this->estimatedCost * $ratio,
            lowerBound: $this->lowerBound * $ratio,
            upperBound: $this->upperBound * $ratio,
            estimatedTokens: $this->estimatedTokens,
            estimatedTurns: $this->estimatedTurns,
            estimatedDurationSeconds: $this->estimatedDurationSeconds,
            confidence: max(0.3, $this->confidence - 0.1), // Less confident when translating
            basis: $this->basis,
            breakdown: $this->breakdown,
        );
    }

    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'estimated_cost' => round($this->estimatedCost, 6),
            'lower_bound' => round($this->lowerBound, 6),
            'upper_bound' => round($this->upperBound, 6),
            'estimated_tokens' => $this->estimatedTokens,
            'estimated_turns' => $this->estimatedTurns,
            'estimated_duration_seconds' => round($this->estimatedDurationSeconds, 1),
            'confidence' => round($this->confidence, 2),
            'basis' => $this->basis,
            'breakdown' => $this->breakdown,
        ];
    }

    private function getModelPriceRatio(string $from, string $to): float
    {
        $prices = [
            'opus' => 1.0,
            'sonnet' => 0.2,
            'haiku' => 0.04,
            'gpt-4' => 0.8,
            'gpt-4o' => 0.15,
            'gpt-3.5' => 0.01,
        ];

        $fromPrice = 1.0;
        $toPrice = 1.0;

        foreach ($prices as $name => $price) {
            if (str_contains(mb_strtolower($from), $name)) {
                $fromPrice = $price;
            }
            if (str_contains(mb_strtolower($to), $name)) {
                $toPrice = $price;
            }
        }

        return $fromPrice > 0 ? $toPrice / $fromPrice : 1.0;
    }
}
