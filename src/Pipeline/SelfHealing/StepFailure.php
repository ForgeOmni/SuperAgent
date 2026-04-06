<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\SelfHealing;

final class StepFailure
{
    public function __construct(
        public readonly string $stepName,
        public readonly string $stepType,
        public readonly array $stepConfig,
        public readonly string $errorMessage,
        public readonly string $errorClass,
        public readonly ?string $stackTrace,
        public readonly int $attemptNumber,
        public readonly array $previousAttempts = [],
        public readonly array $contextSnapshot = [],
        public readonly float $durationMs = 0.0,
    ) {}

    public function isRecoverable(): bool
    {
        $unrecoverable = [
            'InvalidArgumentException',
            'LogicException',
            'TypeError',
            'ParseError',
        ];

        foreach ($unrecoverable as $class) {
            if (str_contains($this->errorClass, $class)) {
                return false;
            }
        }

        return true;
    }

    public function getErrorCategory(): string
    {
        $lower = mb_strtolower($this->errorMessage);

        if (preg_match('/timeout|timed?\s*out|deadline/i', $lower)) {
            return 'timeout';
        }
        if (preg_match('/rate\s*limit|429|too many requests/i', $lower)) {
            return 'rate_limit';
        }
        if (preg_match('/out of memory|memory.*exhaust|allocation/i', $lower)) {
            return 'resource_exhaustion';
        }
        if (preg_match('/connection|network|dns|refused|reset/i', $lower)) {
            return 'external_dependency';
        }
        if (preg_match('/token.*limit|context.*length|too.*long/i', $lower)) {
            return 'model_limitation';
        }
        if (preg_match('/permission|denied|forbidden|unauthorized/i', $lower)) {
            return 'input_error';
        }
        if (preg_match('/not found|missing|undefined|null/i', $lower)) {
            return 'input_error';
        }

        return 'unknown';
    }

    public function toArray(): array
    {
        return [
            'step_name' => $this->stepName,
            'step_type' => $this->stepType,
            'error_message' => $this->errorMessage,
            'error_class' => $this->errorClass,
            'error_category' => $this->getErrorCategory(),
            'is_recoverable' => $this->isRecoverable(),
            'attempt_number' => $this->attemptNumber,
            'previous_attempts' => $this->previousAttempts,
            'duration_ms' => $this->durationMs,
        ];
    }
}
