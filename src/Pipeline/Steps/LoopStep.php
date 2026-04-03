<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;
use SuperAgent\Pipeline\StepStatus;

/**
 * Repeats a body of steps in a loop until an exit condition is met
 * or the maximum iteration count is reached.
 *
 * Designed for review-fix cycles: run reviewers → check for issues →
 * fix issues → re-review, looping until the code is clean or max
 * iterations are exhausted.
 *
 * The loop body is a sequence of steps (any types: agent, parallel,
 * conditional, transform, even nested loops). Each iteration overwrites
 * the previous iteration's step results in the pipeline context, so
 * `{{steps.review.output}}` always refers to the most recent iteration.
 *
 * Iteration metadata is available via:
 *   {{loop.<loop-name>.iteration}}   — current 1-based iteration number
 *   {{loop.<loop-name>.max}}         — max iterations configured
 *
 * YAML example:
 *   - name: review-fix-loop
 *     loop:
 *       max_iterations: 5
 *       exit_when:
 *         output_contains:
 *           step: review
 *           contains: "LGTM"
 *       steps:
 *         - name: review
 *           agent: reviewer
 *           prompt: "Review the code for bugs"
 *         - name: fix
 *           agent: code-writer
 *           prompt: "Fix issues: {{steps.review.output}}"
 *           when:
 *             expression:
 *               left: "{{steps.review.output}}"
 *               operator: contains
 *               right: "BUG"
 *
 * Multi-model review example:
 *   - name: multi-review-loop
 *     loop:
 *       max_iterations: 3
 *       exit_when:
 *         all_passed:
 *           - step: claude-review
 *             contains: "LGTM"
 *           - step: gpt-review
 *             contains: "LGTM"
 *       steps:
 *         - name: reviews
 *           parallel:
 *             - name: claude-review
 *               agent: reviewer
 *               model: claude-sonnet-4-20250514
 *               prompt: "Review for logic bugs"
 *             - name: gpt-review
 *               agent: reviewer
 *               model: gpt-4o
 *               prompt: "Review for security issues"
 *         - name: fix
 *           agent: code-writer
 *           prompt: "Fix all issues found"
 *           input_from:
 *             claude: "{{steps.claude-review.output}}"
 *             gpt: "{{steps.gpt-review.output}}"
 */
class LoopStep extends AbstractStep
{
    /** @var StepInterface[] */
    private readonly array $bodySteps;

    /**
     * @param string $name Loop step name
     * @param StepInterface[] $bodySteps Steps to execute each iteration
     * @param int $maxIterations Hard upper limit (prevents infinite loops)
     * @param array $exitWhen Exit condition config (evaluated after each iteration)
     */
    public function __construct(
        string $name,
        array $bodySteps,
        private readonly int $maxIterations = 5,
        private readonly array $exitWhen = [],
        FailureStrategy $failureStrategy = FailureStrategy::ABORT,
        ?int $timeout = null,
        array $dependsOn = [],
    ) {
        parent::__construct($name, $failureStrategy, $timeout, dependsOn: $dependsOn);
        $this->bodySteps = $bodySteps;
    }

    /**
     * Execute the loop.
     *
     * NOTE: In production, PipelineEngine intercepts LoopStep and routes
     * inner agent steps through the engine's agentRunner. This execute()
     * method is the fallback for non-engine execution (e.g., unit tests
     * with no agent steps).
     */
    public function execute(PipelineContext $context): StepResult
    {
        $start = hrtime(true);
        $iteration = 0;
        $lastBodyResults = [];

        while ($iteration < $this->maxIterations) {
            $iteration++;

            // Set loop iteration metadata in context
            $context->setVariable("loop.{$this->name}.iteration", $iteration);
            $context->setVariable("loop.{$this->name}.max", $this->maxIterations);

            // Execute all body steps
            $iterationFailed = false;
            $lastBodyResults = [];

            foreach ($this->bodySteps as $step) {
                $result = $step->execute($context);
                $context->setStepResult($step->getName(), $result);
                $lastBodyResults[$step->getName()] = $result;

                if ($result->isFailed() && $this->failureStrategy === FailureStrategy::ABORT) {
                    $durationMs = (hrtime(true) - $start) / 1_000_000;

                    return StepResult::failure(
                        stepName: $this->name,
                        error: "Loop iteration {$iteration}: step '{$step->getName()}' failed: {$result->error}",
                        durationMs: $durationMs,
                        metadata: $this->buildMetadata($iteration, 'failed'),
                    );
                }
            }

            // Check exit condition
            if ($this->isExitConditionMet($context)) {
                break;
            }
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $exitReason = $iteration >= $this->maxIterations ? 'max_iterations' : 'exit_condition';

        // Collect final outputs from last iteration
        $outputs = [];
        foreach ($lastBodyResults as $name => $result) {
            $outputs[$name] = $result->output;
        }

        return StepResult::success(
            stepName: $this->name,
            output: $outputs,
            durationMs: $durationMs,
            metadata: $this->buildMetadata($iteration, $exitReason),
        );
    }

    public function describe(): string
    {
        $stepNames = array_map(fn (StepInterface $s) => $s->getName(), $this->bodySteps);

        return "Loop step '{$this->name}': repeat [" . implode(', ', $stepNames)
            . "] up to {$this->maxIterations}x";
    }

    /**
     * @return StepInterface[]
     */
    public function getBodySteps(): array
    {
        return $this->bodySteps;
    }

    public function getMaxIterations(): int
    {
        return $this->maxIterations;
    }

    public function getExitWhen(): array
    {
        return $this->exitWhen;
    }

    /**
     * Check if the exit condition is met.
     */
    public function isExitConditionMet(PipelineContext $context): bool
    {
        if (empty($this->exitWhen)) {
            return false; // No exit condition = run until max_iterations
        }

        foreach ($this->exitWhen as $type => $config) {
            $met = match ($type) {
                'output_contains' => $this->checkOutputContains($context, $config),
                'output_not_contains' => !$this->checkOutputContains($context, $config),
                'expression' => $this->checkExpression($context, $config),
                'all_passed' => $this->checkAllPassed($context, $config),
                'any_passed' => $this->checkAnyPassed($context, $config),
                default => false,
            };

            if (!$met) {
                return false; // All conditions must be met (AND logic)
            }
        }

        return true;
    }

    /**
     * Check if a step's output contains a substring.
     * Format: { step: "review", contains: "LGTM" }
     */
    private function checkOutputContains(PipelineContext $context, array $config): bool
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
     * Evaluate a comparison expression.
     * Format: { left: "{{...}}", operator: "eq", right: "value" }
     */
    private function checkExpression(PipelineContext $context, array $config): bool
    {
        $left = $config['left'] ?? '';
        $operator = $config['operator'] ?? 'eq';
        $right = $config['right'] ?? '';

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
            'not_contains' => is_string($left) && is_string($right) && !str_contains($left, $right),
            'gt' => (float) $left > (float) $right,
            'gte' => (float) $left >= (float) $right,
            'lt' => (float) $left < (float) $right,
            'lte' => (float) $left <= (float) $right,
            default => false,
        };
    }

    /**
     * Check that ALL listed steps' outputs contain their respective substrings.
     *
     * Format:
     *   all_passed:
     *     - { step: "claude-review", contains: "LGTM" }
     *     - { step: "gpt-review", contains: "LGTM" }
     */
    private function checkAllPassed(PipelineContext $context, array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$this->checkOutputContains($context, $check)) {
                return false;
            }
        }

        return !empty($checks);
    }

    /**
     * Check that ANY listed step's output contains its substring.
     */
    private function checkAnyPassed(PipelineContext $context, array $checks): bool
    {
        foreach ($checks as $check) {
            if ($this->checkOutputContains($context, $check)) {
                return true;
            }
        }

        return false;
    }

    private function buildMetadata(int $iterations, string $exitReason): array
    {
        return [
            'iterations' => $iterations,
            'max_iterations' => $this->maxIterations,
            'exit_reason' => $exitReason,
            'body_steps' => count($this->bodySteps),
        ];
    }
}
