<?php

declare(strict_types=1);

namespace SuperAgent\AutoMode;

/**
 * Result of task complexity analysis.
 */
class TaskAnalysisResult
{
    public function __construct(
        private bool $useMultiAgent,
        private string $reason,
        private float $score,
        private array $metrics = [],
    ) {}
    
    public function shouldUseMultiAgent(): bool
    {
        return $this->useMultiAgent;
    }
    
    public function getReason(): string
    {
        return $this->reason;
    }
    
    public function getComplexityScore(): float
    {
        return $this->score;
    }
    
    public function getMetrics(): array
    {
        return $this->metrics;
    }
    
    public function toArray(): array
    {
        return [
            'use_multi_agent' => $this->useMultiAgent,
            'reason' => $this->reason,
            'complexity_score' => $this->score,
            'metrics' => $this->metrics,
        ];
    }
    
    public function __toString(): string
    {
        return sprintf(
            "TaskAnalysis: %s (score: %.2f) - %s",
            $this->useMultiAgent ? 'Multi-Agent' : 'Single-Agent',
            $this->score,
            $this->reason
        );
    }
}