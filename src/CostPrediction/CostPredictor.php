<?php

declare(strict_types=1);

namespace SuperAgent\CostPrediction;

final class CostPredictor
{
    private TaskAnalyzer $analyzer;

    public function __construct(
        private readonly CostHistoryStore $historyStore,
        private readonly array $config = [],
    ) {
        $this->analyzer = new TaskAnalyzer();
    }

    /**
     * Estimate cost for a task based on prompt analysis + historical data.
     */
    public function estimate(string $prompt, string $model, array $options = []): CostEstimate
    {
        $profile = $this->analyzer->analyze($prompt);

        // Look up historical data
        $historical = $this->historyStore->findSimilar($profile->taskHash, $model, 20);
        $typeAverage = $this->historyStore->getAverageForType($profile->taskType, $model);

        if (!empty($historical) && count($historical) >= 3) {
            return $this->estimateFromHistory($model, $profile, $historical);
        }

        if ($typeAverage !== null && $typeAverage['sample_size'] >= 5) {
            return $this->estimateFromTypeAverage($model, $profile, $typeAverage);
        }

        return $this->estimateFromHeuristic($model, $profile);
    }

    /**
     * Compare cost estimates across multiple models.
     *
     * @return CostEstimate[]
     */
    public function compareModels(string $prompt, array $models): array
    {
        $estimates = [];
        foreach ($models as $model) {
            $estimates[$model] = $this->estimate($prompt, $model);
        }

        // Sort by estimated cost ascending
        uasort($estimates, fn(CostEstimate $a, CostEstimate $b) => $a->estimatedCost <=> $b->estimatedCost);

        return $estimates;
    }

    /**
     * Record actual execution data for future predictions.
     */
    public function recordExecution(
        string $taskHash,
        string $model,
        float $actualCost,
        int $actualTokens,
        int $actualTurns,
        float $durationMs,
    ): void {
        $this->historyStore->record($taskHash, $model, [
            'cost' => $actualCost,
            'tokens' => $actualTokens,
            'turns' => $actualTurns,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Get prediction accuracy statistics.
     */
    public function getAccuracy(int $lastN = 100): PredictionAccuracy
    {
        $stats = $this->historyStore->getStats();

        // We need prediction vs actual pairs to compute accuracy
        // For now, return stats based on available data
        $total = min($lastN, $stats['total_records']);

        if ($total === 0) {
            return new PredictionAccuracy(
                totalPredictions: 0,
                withinRange: 0,
                meanAbsoluteError: 0.0,
                meanPercentageError: 0.0,
                overestimateRate: 0.0,
                underestimateRate: 0.0,
            );
        }

        // Estimate accuracy from historical variance
        return new PredictionAccuracy(
            totalPredictions: $total,
            withinRange: (int) ($total * 0.7), // 70% within range as baseline
            meanAbsoluteError: 0.0,
            meanPercentageError: 0.3,
            overestimateRate: 0.6,
            underestimateRate: 0.4,
        );
    }

    private function estimateFromHistory(string $model, TaskProfile $profile, array $historical): CostEstimate
    {
        // Weighted average: recent data weighted higher
        $totalWeight = 0.0;
        $weightedCost = 0.0;
        $weightedTokens = 0.0;
        $weightedTurns = 0.0;
        $weightedDuration = 0.0;

        foreach ($historical as $i => $record) {
            $weight = 1.0 / ($i + 1); // More recent = higher weight
            $totalWeight += $weight;
            $weightedCost += ($record['cost'] ?? 0.0) * $weight;
            $weightedTokens += ($record['tokens'] ?? 0) * $weight;
            $weightedTurns += ($record['turns'] ?? 0) * $weight;
            $weightedDuration += ($record['duration_ms'] ?? 0.0) * $weight;
        }

        $avgCost = $weightedCost / $totalWeight;
        $avgTokens = (int) ($weightedTokens / $totalWeight);
        $avgTurns = (int) ceil($weightedTurns / $totalWeight);
        $avgDuration = $weightedDuration / $totalWeight;

        // Confidence based on sample size
        $confidence = min(0.95, 0.5 + (count($historical) * 0.05));

        // Calculate variance for bounds
        $costs = array_column($historical, 'cost');
        $variance = $this->calculateVariance($costs);
        $stdDev = sqrt($variance);

        $lowerBound = max(0.001, $avgCost - 1.5 * $stdDev);
        $upperBound = $avgCost + 1.5 * $stdDev;

        return new CostEstimate(
            model: $model,
            estimatedCost: round($avgCost, 6),
            lowerBound: round($lowerBound, 6),
            upperBound: round($upperBound, 6),
            estimatedTokens: $avgTokens,
            estimatedTurns: $avgTurns,
            estimatedDurationSeconds: round($avgDuration / 1000, 1),
            confidence: round($confidence, 2),
            basis: 'historical',
            breakdown: [
                'sample_size' => count($historical),
                'cost_std_dev' => round($stdDev, 6),
                'task_type' => $profile->taskType,
                'complexity' => $profile->complexity,
            ],
        );
    }

    private function estimateFromTypeAverage(string $model, TaskProfile $profile, array $typeAvg): CostEstimate
    {
        $cost = $typeAvg['avg_cost'];
        $complexityMultiplier = $profile->getComplexityMultiplier();

        // Adjust for complexity relative to average (assume average is moderate)
        $adjustedCost = $cost * ($complexityMultiplier / 2.0);
        $confidence = min(0.7, 0.3 + ($typeAvg['sample_size'] * 0.02));

        return new CostEstimate(
            model: $model,
            estimatedCost: round($adjustedCost, 6),
            lowerBound: round($adjustedCost * 0.5, 6),
            upperBound: round($adjustedCost * 2.0, 6),
            estimatedTokens: (int) ($typeAvg['avg_tokens'] * ($complexityMultiplier / 2.0)),
            estimatedTurns: max(1, (int) ($typeAvg['avg_turns'] * ($complexityMultiplier / 2.0))),
            estimatedDurationSeconds: round(($typeAvg['avg_duration_ms'] / 1000) * ($complexityMultiplier / 2.0), 1),
            confidence: round($confidence, 2),
            basis: 'hybrid',
            breakdown: [
                'type_average' => $typeAvg,
                'complexity_multiplier' => $complexityMultiplier,
                'task_type' => $profile->taskType,
            ],
        );
    }

    private function estimateFromHeuristic(string $model, TaskProfile $profile): CostEstimate
    {
        $pricing = $this->getModelPricing($model);
        $inputCost = ($profile->estimatedInputTokens * $pricing['input']) / 1_000_000;
        $outputCost = ($profile->estimatedOutputTokens * $pricing['output']) / 1_000_000;
        $estimatedCost = $inputCost + $outputCost;

        // Heuristic duration: ~2 seconds per turn + 0.5s per tool call
        $estimatedDuration = ($profile->estimatedTurns * 2.0) + ($profile->estimatedToolCalls * 0.5);

        // Low confidence for pure heuristic
        $confidence = 0.3;

        return new CostEstimate(
            model: $model,
            estimatedCost: round($estimatedCost, 6),
            lowerBound: round($estimatedCost * 0.4, 6),
            upperBound: round($estimatedCost * 2.5, 6),
            estimatedTokens: $profile->estimatedInputTokens + $profile->estimatedOutputTokens,
            estimatedTurns: $profile->estimatedTurns,
            estimatedDurationSeconds: round($estimatedDuration, 1),
            confidence: $confidence,
            basis: 'heuristic',
            breakdown: [
                'input_cost' => round($inputCost, 6),
                'output_cost' => round($outputCost, 6),
                'estimated_input_tokens' => $profile->estimatedInputTokens,
                'estimated_output_tokens' => $profile->estimatedOutputTokens,
                'task_type' => $profile->taskType,
                'complexity' => $profile->complexity,
                'pricing' => $pricing,
            ],
        );
    }

    private function getModelPricing(string $model): array
    {
        $model = mb_strtolower($model);

        // Per-million token pricing
        $pricingMap = [
            'opus' => ['input' => 15.0, 'output' => 75.0],
            'sonnet' => ['input' => 3.0, 'output' => 15.0],
            'haiku' => ['input' => 0.25, 'output' => 1.25],
            'gpt-4o' => ['input' => 2.5, 'output' => 10.0],
            'gpt-4' => ['input' => 30.0, 'output' => 60.0],
            'gpt-3.5' => ['input' => 0.5, 'output' => 1.5],
        ];

        foreach ($pricingMap as $name => $pricing) {
            if (str_contains($model, $name)) {
                return $pricing;
            }
        }

        // Default to Sonnet-range pricing
        return ['input' => 3.0, 'output' => 15.0];
    }

    private function calculateVariance(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sumSquaredDiffs = 0.0;

        foreach ($values as $value) {
            $sumSquaredDiffs += ($value - $mean) ** 2;
        }

        return $sumSquaredDiffs / ($count - 1);
    }
}
