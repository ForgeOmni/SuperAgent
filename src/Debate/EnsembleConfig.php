<?php

declare(strict_types=1);

namespace SuperAgent\Debate;

final class EnsembleConfig
{
    public int $agents = 3;
    public array $models = ['sonnet'];
    public string $mergerModel = 'opus';
    public int $maxTurnsPerAgent = 10;
    public float $maxBudget = 5.0;
    public bool $parallel = true;
    public string $mergeCriteria = 'Combine the best elements from each solution into a single optimal result.';

    public static function create(): self
    {
        return new self();
    }

    public function withAgentCount(int $count): self
    {
        $this->agents = $count;
        return $this;
    }

    public function withModels(array $models): self
    {
        $this->models = $models;
        return $this;
    }

    public function withMergerModel(string $model): self
    {
        $this->mergerModel = $model;
        return $this;
    }

    public function withMaxTurnsPerAgent(int $turns): self
    {
        $this->maxTurnsPerAgent = $turns;
        return $this;
    }

    public function withMaxBudget(float $budget): self
    {
        $this->maxBudget = $budget;
        return $this;
    }

    public function withParallel(bool $parallel): self
    {
        $this->parallel = $parallel;
        return $this;
    }

    public function withMergeCriteria(string $criteria): self
    {
        $this->mergeCriteria = $criteria;
        return $this;
    }

    /**
     * Get the model for a specific agent index.
     */
    public function getModelForAgent(int $index): string
    {
        if (count($this->models) === 1) {
            return $this->models[0];
        }
        return $this->models[$index] ?? $this->models[0];
    }
}
