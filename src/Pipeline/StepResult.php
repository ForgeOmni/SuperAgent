<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

/**
 * Result of a single pipeline step execution.
 */
class StepResult
{
    public function __construct(
        public readonly string $stepName,
        public readonly StepStatus $status,
        public readonly mixed $output = null,
        public readonly ?string $error = null,
        public readonly float $durationMs = 0,
        public readonly array $metadata = [],
    ) {}

    public static function success(string $stepName, mixed $output = null, float $durationMs = 0, array $metadata = []): self
    {
        return new self(
            stepName: $stepName,
            status: StepStatus::COMPLETED,
            output: $output,
            durationMs: $durationMs,
            metadata: $metadata,
        );
    }

    public static function failure(string $stepName, string $error, float $durationMs = 0, array $metadata = []): self
    {
        return new self(
            stepName: $stepName,
            status: StepStatus::FAILED,
            error: $error,
            durationMs: $durationMs,
            metadata: $metadata,
        );
    }

    public static function skipped(string $stepName, ?string $reason = null): self
    {
        return new self(
            stepName: $stepName,
            status: StepStatus::SKIPPED,
            error: $reason,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === StepStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === StepStatus::FAILED;
    }
}
