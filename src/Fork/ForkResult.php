<?php

declare(strict_types=1);

namespace SuperAgent\Fork;

final class ForkResult
{
    public function __construct(
        public readonly string $sessionId,
        public readonly array $branches,
        public readonly float $totalCost,
        public readonly float $totalDurationMs,
        public readonly int $completedCount,
        public readonly int $failedCount,
    ) {}

    /**
     * Apply a scorer and return the highest-scoring branch.
     */
    public function getBest(callable $scorer): ?ForkBranch
    {
        $ranked = $this->getRanked($scorer);
        return $ranked[0] ?? null;
    }

    /**
     * All branches sorted by score descending.
     */
    public function getRanked(callable $scorer): array
    {
        $completed = $this->getCompleted();

        foreach ($completed as $branch) {
            $branch->score = (float) $scorer($branch);
        }

        usort($completed, fn(ForkBranch $a, ForkBranch $b) => ($b->score ?? 0) <=> ($a->score ?? 0));

        return $completed;
    }

    /**
     * @return ForkBranch[]
     */
    public function getCompleted(): array
    {
        return array_values(array_filter(
            $this->branches,
            fn(ForkBranch $b) => $b->isCompleted(),
        ));
    }

    /**
     * @return ForkBranch[]
     */
    public function getFailed(): array
    {
        return array_values(array_filter(
            $this->branches,
            fn(ForkBranch $b) => $b->isFailed(),
        ));
    }

    public function getSummary(): array
    {
        return [
            'session_id' => $this->sessionId,
            'total_branches' => count($this->branches),
            'completed' => $this->completedCount,
            'failed' => $this->failedCount,
            'total_cost' => round($this->totalCost, 4),
            'total_duration_ms' => round($this->totalDurationMs, 2),
            'branches' => array_map(fn(ForkBranch $b) => [
                'id' => $b->id,
                'status' => $b->status,
                'cost' => $b->cost,
                'turns' => $b->turns,
                'score' => $b->score,
            ], $this->branches),
        ];
    }
}
