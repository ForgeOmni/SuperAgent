<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

use SuperAgent\Pipeline\Steps\StepInterface;

/**
 * Immutable definition of a single pipeline parsed from YAML.
 */
class PipelineDefinition
{
    /**
     * @param StepInterface[] $steps
     * @param array<array{name: string, type?: string, required?: bool, default?: mixed}> $inputs
     * @param array<string, string> $outputs Template strings for final outputs
     * @param array<array{event: string}> $triggers
     */
    public function __construct(
        public readonly string $name,
        public readonly array $steps,
        public readonly ?string $description = null,
        public readonly array $inputs = [],
        public readonly array $outputs = [],
        public readonly array $triggers = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Validate that required inputs are present.
     *
     * @return string[] Error messages for missing inputs
     */
    public function validateInputs(array $providedInputs): array
    {
        $errors = [];

        foreach ($this->inputs as $inputDef) {
            $name = $inputDef['name'] ?? null;
            if ($name === null) {
                continue;
            }

            $required = $inputDef['required'] ?? false;

            if ($required && !array_key_exists($name, $providedInputs)) {
                $errors[] = "Missing required input: '{$name}'";
            }
        }

        return $errors;
    }

    /**
     * Apply default values to inputs.
     */
    public function applyInputDefaults(array $providedInputs): array
    {
        foreach ($this->inputs as $inputDef) {
            $name = $inputDef['name'] ?? null;
            if ($name === null) {
                continue;
            }

            if (!array_key_exists($name, $providedInputs) && array_key_exists('default', $inputDef)) {
                $providedInputs[$name] = $inputDef['default'];
            }
        }

        return $providedInputs;
    }

    /**
     * Resolve output templates using the pipeline context.
     *
     * @return array<string, mixed>
     */
    public function resolveOutputs(PipelineContext $context): array
    {
        $resolved = [];

        foreach ($this->outputs as $key => $template) {
            $resolved[$key] = is_string($template)
                ? $context->resolveTemplate($template)
                : $template;
        }

        return $resolved;
    }

    /**
     * Check if this pipeline has a specific trigger.
     */
    public function hasTrigger(string $event): bool
    {
        foreach ($this->triggers as $trigger) {
            if (($trigger['event'] ?? null) === $event) {
                return true;
            }
        }

        return false;
    }
}
