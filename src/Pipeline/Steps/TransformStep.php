<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;

/**
 * Transforms and aggregates data from previous steps.
 *
 * Supports several transform operations:
 *   - merge: Combine outputs from multiple steps into a single object
 *   - template: Build a string from templates
 *   - extract: Extract a field from a step's output
 *   - map: Apply a mapping over the outputs
 *
 * YAML example:
 *   - name: aggregate-results
 *     transform:
 *       type: merge
 *       sources:
 *         security: "{{steps.security-scan.output}}"
 *         style: "{{steps.style-check.output}}"
 *         tests: "{{steps.test-coverage.output}}"
 *
 *   - name: build-report
 *     transform:
 *       type: template
 *       template: |
 *         # Review Report
 *         ## Security: {{steps.security-scan.status}}
 *         {{steps.security-scan.output}}
 *         ## Style: {{steps.style-check.status}}
 *         {{steps.style-check.output}}
 */
class TransformStep extends AbstractStep
{
    public function __construct(
        string $name,
        private readonly string $type,
        private readonly array $config,
        FailureStrategy $failureStrategy = FailureStrategy::CONTINUE,
        ?int $timeout = null,
        array $dependsOn = [],
    ) {
        parent::__construct($name, $failureStrategy, $timeout, dependsOn: $dependsOn);
    }

    public function execute(PipelineContext $context): StepResult
    {
        try {
            [$output, $durationMs] = $this->timed(fn () => $this->transform($context));

            return StepResult::success(
                stepName: $this->name,
                output: $output,
                durationMs: $durationMs,
                metadata: ['transform_type' => $this->type],
            );
        } catch (\Throwable $e) {
            return StepResult::failure(
                stepName: $this->name,
                error: "Transform failed: {$e->getMessage()}",
            );
        }
    }

    public function describe(): string
    {
        return "Transform step '{$this->name}': {$this->type}";
    }

    /**
     * Get the transform type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    private function transform(PipelineContext $context): mixed
    {
        return match ($this->type) {
            'merge' => $this->transformMerge($context),
            'template' => $this->transformTemplate($context),
            'extract' => $this->transformExtract($context),
            'map' => $this->transformMap($context),
            default => throw new \InvalidArgumentException("Unknown transform type: {$this->type}"),
        };
    }

    /**
     * Merge multiple step outputs into a single associative array.
     */
    private function transformMerge(PipelineContext $context): array
    {
        $sources = $this->config['sources'] ?? [];
        $result = [];

        foreach ($sources as $key => $template) {
            $result[$key] = is_string($template)
                ? $context->resolveTemplate($template)
                : $template;
        }

        return $result;
    }

    /**
     * Build a string from a template with variable resolution.
     */
    private function transformTemplate(PipelineContext $context): string
    {
        $template = $this->config['template'] ?? '';

        return $context->resolveTemplate($template);
    }

    /**
     * Extract a specific field from a step's output.
     */
    private function transformExtract(PipelineContext $context): mixed
    {
        $step = $this->config['step'] ?? null;
        $field = $this->config['field'] ?? null;

        if ($step === null) {
            throw new \InvalidArgumentException("Transform 'extract' requires 'step' config");
        }

        $output = $context->getStepOutput($step);

        if ($field === null) {
            return $output;
        }

        if (is_array($output) && array_key_exists($field, $output)) {
            return $output[$field];
        }

        return null;
    }

    /**
     * Apply a template to each value in a step's array output.
     */
    private function transformMap(PipelineContext $context): array
    {
        $step = $this->config['step'] ?? null;
        $template = $this->config['template'] ?? '{{item}}';

        if ($step === null) {
            throw new \InvalidArgumentException("Transform 'map' requires 'step' config");
        }

        $output = $context->getStepOutput($step);
        if (!is_array($output)) {
            return [];
        }

        $results = [];
        foreach ($output as $key => $item) {
            $context->setVariable('item', $item);
            $context->setVariable('item_key', $key);
            $results[$key] = $context->resolveTemplate($template);
        }

        return $results;
    }
}
