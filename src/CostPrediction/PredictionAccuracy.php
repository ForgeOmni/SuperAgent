<?php

declare(strict_types=1);

namespace SuperAgent\CostPrediction;

final class PredictionAccuracy
{
    public function __construct(
        public readonly int $totalPredictions,
        public readonly int $withinRange,
        public readonly float $meanAbsoluteError,
        public readonly float $meanPercentageError,
        public readonly float $overestimateRate,
        public readonly float $underestimateRate,
    ) {}

    public function getAccuracyRate(): float
    {
        if ($this->totalPredictions === 0) {
            return 0.0;
        }
        return $this->withinRange / $this->totalPredictions;
    }

    public function format(): string
    {
        $accuracy = (int) ($this->getAccuracyRate() * 100);
        $mape = (int) ($this->meanPercentageError * 100);
        $mae = number_format($this->meanAbsoluteError, 4);
        $over = (int) ($this->overestimateRate * 100);
        $under = (int) ($this->underestimateRate * 100);

        return "Accuracy: {$accuracy}% within range ({$this->withinRange}/{$this->totalPredictions}), MAE: \${$mae}, MAPE: {$mape}%, Over: {$over}%, Under: {$under}%";
    }

    public function toArray(): array
    {
        return [
            'total_predictions' => $this->totalPredictions,
            'within_range' => $this->withinRange,
            'accuracy_rate' => round($this->getAccuracyRate(), 4),
            'mean_absolute_error' => round($this->meanAbsoluteError, 6),
            'mean_percentage_error' => round($this->meanPercentageError, 4),
            'overestimate_rate' => round($this->overestimateRate, 4),
            'underestimate_rate' => round($this->underestimateRate, 4),
        ];
    }
}
