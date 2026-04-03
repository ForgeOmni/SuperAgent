<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

/**
 * Runtime context for pipeline execution.
 *
 * Tracks step results, resolved inputs, and provides template variable
 * resolution for inter-step data flow (e.g., {{steps.scan.output}}).
 */
class PipelineContext
{
    /** @var array<string, StepResult> */
    private array $stepResults = [];

    /** @var array<string, mixed> */
    private array $inputs = [];

    /** @var array<string, mixed> */
    private array $variables = [];

    private bool $cancelled = false;

    public function __construct(array $inputs = [])
    {
        $this->inputs = $inputs;
    }

    /**
     * Record the result of a step.
     */
    public function setStepResult(string $stepName, StepResult $result): void
    {
        $this->stepResults[$stepName] = $result;
    }

    public function getStepResult(string $stepName): ?StepResult
    {
        return $this->stepResults[$stepName] ?? null;
    }

    /**
     * @return StepResult[]
     */
    public function getAllStepResults(): array
    {
        return array_values($this->stepResults);
    }

    /**
     * Get a step's output by name.
     */
    public function getStepOutput(string $stepName): mixed
    {
        return $this->stepResults[$stepName]?->output ?? null;
    }

    /**
     * Check if a step has completed successfully.
     */
    public function isStepCompleted(string $stepName): bool
    {
        $result = $this->stepResults[$stepName] ?? null;

        return $result !== null && $result->status === StepStatus::COMPLETED;
    }

    /**
     * Check if a step has failed.
     */
    public function isStepFailed(string $stepName): bool
    {
        $result = $this->stepResults[$stepName] ?? null;

        return $result !== null && $result->status === StepStatus::FAILED;
    }

    /**
     * Get pipeline inputs.
     */
    public function getInputs(): array
    {
        return $this->inputs;
    }

    public function getInput(string $key): mixed
    {
        return $this->inputs[$key] ?? null;
    }

    /**
     * Set a custom variable.
     */
    public function setVariable(string $key, mixed $value): void
    {
        $this->variables[$key] = $value;
    }

    public function getVariable(string $key): mixed
    {
        return $this->variables[$key] ?? null;
    }

    /**
     * Cancel the pipeline execution.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Resolve template variables in a string.
     *
     * Supported patterns:
     *   {{inputs.key}}           - Pipeline input value
     *   {{steps.name.output}}    - Step output
     *   {{steps.name.status}}    - Step status
     *   {{steps.name.error}}     - Step error message
     *   {{vars.key}}             - Custom variable
     */
    public function resolveTemplate(string $template): string
    {
        return preg_replace_callback('/\{\{(.+?)\}\}/', function (array $matches) {
            $path = trim($matches[1]);
            $resolved = $this->resolvePath($path);

            if ($resolved === null) {
                return $matches[0]; // Keep unresolved placeholders as-is
            }

            if (is_array($resolved) || is_object($resolved)) {
                return json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return (string) $resolved;
        }, $template) ?? $template;
    }

    /**
     * Resolve a dot-notation path to a value.
     */
    private function resolvePath(string $path): mixed
    {
        $parts = explode('.', $path);

        if (count($parts) < 2) {
            return null;
        }

        $root = $parts[0];

        return match ($root) {
            'inputs' => $this->resolveInputPath(array_slice($parts, 1)),
            'steps' => $this->resolveStepPath(array_slice($parts, 1)),
            'vars' => $this->variables[$parts[1]] ?? null,
            default => null,
        };
    }

    private function resolveInputPath(array $parts): mixed
    {
        $value = $this->inputs;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function resolveStepPath(array $parts): mixed
    {
        if (count($parts) < 2) {
            return null;
        }

        $stepName = $parts[0];
        $field = $parts[1];
        $result = $this->stepResults[$stepName] ?? null;

        if ($result === null) {
            return null;
        }

        return match ($field) {
            'output' => $result->output,
            'status' => $result->status->value,
            'error' => $result->error,
            'duration_ms' => $result->durationMs,
            default => $result->metadata[$field] ?? null,
        };
    }
}
