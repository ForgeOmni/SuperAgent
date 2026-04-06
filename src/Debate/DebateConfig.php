<?php

declare(strict_types=1);

namespace SuperAgent\Debate;

final class DebateConfig
{
    public string $proposerModel = 'opus';
    public string $criticModel = 'sonnet';
    public string $judgeModel = 'opus';
    public ?string $proposerSystemPrompt = null;
    public ?string $criticSystemPrompt = null;
    public ?string $judgeSystemPrompt = null;
    public int $rounds = 3;
    public int $maxTurnsPerRound = 5;
    public float $maxBudget = 5.0;
    public bool $allowTools = true;
    public array $allowedTools = [];
    public string $judgingCriteria = 'Choose the approach that is most correct, practical, and well-reasoned.';

    public static function create(): self
    {
        return new self();
    }

    public function withProposerModel(string $model): self
    {
        $this->proposerModel = $model;
        return $this;
    }

    public function withCriticModel(string $model): self
    {
        $this->criticModel = $model;
        return $this;
    }

    public function withJudgeModel(string $model): self
    {
        $this->judgeModel = $model;
        return $this;
    }

    public function withProposerPrompt(string $prompt): self
    {
        $this->proposerSystemPrompt = $prompt;
        return $this;
    }

    public function withCriticPrompt(string $prompt): self
    {
        $this->criticSystemPrompt = $prompt;
        return $this;
    }

    public function withJudgePrompt(string $prompt): self
    {
        $this->judgeSystemPrompt = $prompt;
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

    public function withTools(bool $allow, array $tools = []): self
    {
        $this->allowTools = $allow;
        $this->allowedTools = $tools;
        return $this;
    }

    public function withJudgingCriteria(string $criteria): self
    {
        $this->judgingCriteria = $criteria;
        return $this;
    }
}
