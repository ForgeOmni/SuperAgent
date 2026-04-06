<?php

declare(strict_types=1);

namespace SuperAgent\Debate;

final class RedTeamConfig
{
    public string $builderModel = 'opus';
    public string $attackerModel = 'sonnet';
    public string $reviewerModel = 'opus';
    public int $rounds = 3;
    public int $maxTurnsPerRound = 5;
    public float $maxBudget = 5.0;
    public array $attackVectors = ['security', 'edge_cases', 'performance', 'error_handling'];
    public ?string $builderSystemPrompt = null;
    public ?string $attackerSystemPrompt = null;

    public static function create(): self
    {
        return new self();
    }

    public function withBuilderModel(string $model): self
    {
        $this->builderModel = $model;
        return $this;
    }

    public function withAttackerModel(string $model): self
    {
        $this->attackerModel = $model;
        return $this;
    }

    public function withReviewerModel(string $model): self
    {
        $this->reviewerModel = $model;
        return $this;
    }

    public function withRounds(int $rounds): self
    {
        $this->rounds = $rounds;
        return $this;
    }

    public function withMaxTurnsPerRound(int $turns): self
    {
        $this->maxTurnsPerRound = $turns;
        return $this;
    }

    public function withMaxBudget(float $budget): self
    {
        $this->maxBudget = $budget;
        return $this;
    }

    public function withAttackVectors(array $vectors): self
    {
        $this->attackVectors = $vectors;
        return $this;
    }

    public function withBuilderPrompt(string $prompt): self
    {
        $this->builderSystemPrompt = $prompt;
        return $this;
    }

    public function withAttackerPrompt(string $prompt): self
    {
        $this->attackerSystemPrompt = $prompt;
        return $this;
    }
}
