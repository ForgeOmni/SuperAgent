<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

/**
 * Result of a complete pipeline execution.
 */
class PipelineResult
{
    /** @var StepResult[] */
    private array $stepResults = [];

    public function __construct(
        public readonly string $pipelineName,
        public readonly StepStatus $status,
        public readonly float $totalDurationMs = 0,
        public readonly ?string $error = null,
        array $stepResults = [],
    ) {
        $this->stepResults = $stepResults;
    }

    public static function success(string $pipelineName, array $stepResults, float $totalDurationMs): self
    {
        return new self(
            pipelineName: $pipelineName,
            status: StepStatus::COMPLETED,
            totalDurationMs: $totalDurationMs,
            stepResults: $stepResults,
        );
    }

    public static function failure(string $pipelineName, string $error, array $stepResults, float $totalDurationMs): self
    {
        return new self(
            pipelineName: $pipelineName,
            status: StepStatus::FAILED,
            totalDurationMs: $totalDurationMs,
            error: $error,
            stepResults: $stepResults,
        );
    }

    public static function cancelled(string $pipelineName, array $stepResults, float $totalDurationMs): self
    {
        return new self(
            pipelineName: $pipelineName,
            status: StepStatus::CANCELLED,
            totalDurationMs: $totalDurationMs,
            stepResults: $stepResults,
        );
    }

    /**
     * @return StepResult[]
     */
    public function getStepResults(): array
    {
        return $this->stepResults;
    }

    /**
     * Get the result of a specific step by name.
     */
    public function getStepResult(string $stepName): ?StepResult
    {
        foreach ($this->stepResults as $result) {
            if ($result->stepName === $stepName) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Get the output of a specific step by name.
     */
    public function getStepOutput(string $stepName): mixed
    {
        return $this->getStepResult($stepName)?->output;
    }

    /**
     * Get all outputs keyed by step name.
     *
     * @return array<string, mixed>
     */
    public function getAllOutputs(): array
    {
        $outputs = [];
        foreach ($this->stepResults as $result) {
            $outputs[$result->stepName] = $result->output;
        }

        return $outputs;
    }

    public function isSuccessful(): bool
    {
        return $this->status === StepStatus::COMPLETED;
    }

    /**
     * Get a summary of the pipeline execution.
     *
     * @return array{pipeline: string, status: string, duration_ms: float, steps: int, completed: int, failed: int, skipped: int}
     */
    public function getSummary(): array
    {
        $completed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->stepResults as $result) {
            match ($result->status) {
                StepStatus::COMPLETED => $completed++,
                StepStatus::FAILED => $failed++,
                StepStatus::SKIPPED => $skipped++,
                default => null,
            };
        }

        return [
            'pipeline' => $this->pipelineName,
            'status' => $this->status->value,
            'duration_ms' => $this->totalDurationMs,
            'steps' => count($this->stepResults),
            'completed' => $completed,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }
}
