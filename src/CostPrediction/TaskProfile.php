<?php

declare(strict_types=1);

namespace SuperAgent\CostPrediction;

final class TaskProfile
{
    public const TYPE_CODE_GENERATION = 'code_generation';
    public const TYPE_REFACTORING = 'refactoring';
    public const TYPE_TESTING = 'testing';
    public const TYPE_DEBUGGING = 'debugging';
    public const TYPE_ANALYSIS = 'analysis';
    public const TYPE_CHAT = 'chat';
    public const TYPE_MULTI_FILE = 'multi_file';

    public const COMPLEXITY_SIMPLE = 'simple';
    public const COMPLEXITY_MODERATE = 'moderate';
    public const COMPLEXITY_COMPLEX = 'complex';
    public const COMPLEXITY_VERY_COMPLEX = 'very_complex';

    public function __construct(
        public readonly string $taskType,
        public readonly string $complexity,
        public readonly int $estimatedToolCalls,
        public readonly array $likelyTools,
        public readonly int $estimatedTurns,
        public readonly int $estimatedInputTokens,
        public readonly int $estimatedOutputTokens,
        public readonly string $taskHash,
    ) {}

    public function getComplexityMultiplier(): float
    {
        return match ($this->complexity) {
            self::COMPLEXITY_SIMPLE => 1.0,
            self::COMPLEXITY_MODERATE => 2.0,
            self::COMPLEXITY_COMPLEX => 4.0,
            self::COMPLEXITY_VERY_COMPLEX => 8.0,
            default => 2.0,
        };
    }

    public function toArray(): array
    {
        return [
            'task_type' => $this->taskType,
            'complexity' => $this->complexity,
            'estimated_tool_calls' => $this->estimatedToolCalls,
            'likely_tools' => $this->likelyTools,
            'estimated_turns' => $this->estimatedTurns,
            'estimated_input_tokens' => $this->estimatedInputTokens,
            'estimated_output_tokens' => $this->estimatedOutputTokens,
            'task_hash' => $this->taskHash,
            'complexity_multiplier' => $this->getComplexityMultiplier(),
        ];
    }
}
