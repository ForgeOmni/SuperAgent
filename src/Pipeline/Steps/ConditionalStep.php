<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;

/**
 * Conditionally execute a step based on the pipeline context.
 *
 * Supports several condition types:
 *   - step_succeeded / step_failed: check a previous step's outcome
 *   - expression: evaluate a simple expression against context values
 *   - input_equals: check pipeline input values
 *
 * YAML example:
 *   - name: deploy
 *     when:
 *       step_succeeded: all-tests
 *     agent: deployer
 *     prompt: "Deploy the changes"
 *
 *   - name: notify-failure
 *     when:
 *       step_failed: all-tests
 *     agent: notifier
 *     prompt: "Notify team of test failures: {{steps.all-tests.error}}"
 */
class ConditionalStep extends AbstractStep
{
    public function __construct(
        string $name,
        private readonly StepInterface $innerStep,
        private readonly array $condition,
        FailureStrategy $failureStrategy = FailureStrategy::ABORT,
        ?int $timeout = null,
        array $dependsOn = [],
    ) {
        parent::__construct($name, $failureStrategy, $timeout, dependsOn: $dependsOn);
    }

    public function execute(PipelineContext $context): StepResult
    {
        if (!$this->evaluateCondition($context)) {
            return StepResult::skipped($this->name, 'Condition not met');
        }

        return $this->innerStep->execute($context);
    }

    /**
     * Evaluate the condition without executing the inner step.
     */
    public function isConditionMet(PipelineContext $context): bool
    {
        return $this->evaluateCondition($context);
    }

    public function describe(): string
    {
        $condDesc = json_encode($this->condition, JSON_UNESCAPED_SLASHES);

        return "Conditional step '{$this->name}': if {$condDesc} then {$this->innerStep->describe()}";
    }

    /**
     * Get the condition configuration.
     */
    public function getCondition(): array
    {
        return $this->condition;
    }

    /**
     * Get the wrapped inner step.
     */
    public function getInnerStep(): StepInterface
    {
        return $this->innerStep;
    }

    /**
     * Evaluate the condition against the current pipeline context.
     */
    private function evaluateCondition(PipelineContext $context): bool
    {
        foreach ($this->condition as $type => $value) {
            $result = match ($type) {
                'step_succeeded' => $context->isStepCompleted($value),
                'step_failed' => $context->isStepFailed($value),
                'input_equals' => $this->evaluateInputEquals($context, $value),
                'output_contains' => $this->evaluateOutputContains($context, $value),
                'expression' => $this->evaluateExpression($context, $value),
                default => false,
            };

            if (!$result) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a pipeline input matches an expected value.
     * Format: { field: "key", value: "expected" }
     */
    private function evaluateInputEquals(PipelineContext $context, array $config): bool
    {
        $field = $config['field'] ?? null;
        $expected = $config['value'] ?? null;

        if ($field === null) {
            return false;
        }

        return $context->getInput($field) === $expected;
    }

    /**
     * Check if a step's output contains a substring.
     * Format: { step: "name", contains: "text" }
     */
    private function evaluateOutputContains(PipelineContext $context, array $config): bool
    {
        $stepName = $config['step'] ?? null;
        $contains = $config['contains'] ?? null;

        if ($stepName === null || $contains === null) {
            return false;
        }

        $output = $context->getStepOutput($stepName);
        if (!is_string($output)) {
            return false;
        }

        return str_contains($output, $contains);
    }

    /**
     * Evaluate a simple comparison expression.
     * Format: { left: "{{steps.scan.status}}", operator: "eq", right: "completed" }
     */
    private function evaluateExpression(PipelineContext $context, array $config): bool
    {
        $left = $config['left'] ?? '';
        $operator = $config['operator'] ?? 'eq';
        $right = $config['right'] ?? '';

        // Resolve templates in left and right
        if (is_string($left)) {
            $left = $context->resolveTemplate($left);
        }
        if (is_string($right)) {
            $right = $context->resolveTemplate($right);
        }

        return match ($operator) {
            'eq' => $left === $right,
            'neq', 'ne' => $left !== $right,
            'contains' => is_string($left) && is_string($right) && str_contains($left, $right),
            'gt' => (float) $left > (float) $right,
            'gte' => (float) $left >= (float) $right,
            'lt' => (float) $left < (float) $right,
            'lte' => (float) $left <= (float) $right,
            default => false,
        };
    }
}
