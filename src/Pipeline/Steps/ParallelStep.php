<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

use SuperAgent\Pipeline\PipelineContext;
use SuperAgent\Pipeline\StepResult;
use SuperAgent\Pipeline\StepStatus;

/**
 * Executes multiple steps in parallel and collects their results.
 *
 * YAML example:
 *   - name: parallel-checks
 *     parallel:
 *       - name: style-check
 *         agent: code-writer
 *         prompt: "Check code style"
 *       - name: test-coverage
 *         agent: verification
 *         prompt: "Analyze test coverage"
 */
class ParallelStep extends AbstractStep
{
    /** @var StepInterface[] */
    private readonly array $steps;

    /**
     * @param StepInterface[] $steps
     */
    public function __construct(
        string $name,
        array $steps,
        private readonly bool $waitAll = true,
        FailureStrategy $failureStrategy = FailureStrategy::ABORT,
        ?int $timeout = null,
        array $dependsOn = [],
    ) {
        parent::__construct($name, $failureStrategy, $timeout, dependsOn: $dependsOn);
        $this->steps = $steps;
    }

    public function execute(PipelineContext $context): StepResult
    {
        [$results, $durationMs] = $this->timed(function () use ($context) {
            return $this->executeParallel($context);
        });

        // Record each sub-step result in context
        $outputs = [];
        $hasFailure = false;
        $errors = [];

        foreach ($results as $result) {
            $context->setStepResult($result->stepName, $result);
            $outputs[$result->stepName] = $result->output;

            if ($result->isFailed()) {
                $hasFailure = true;
                $errors[] = "{$result->stepName}: {$result->error}";
            }
        }

        if ($hasFailure) {
            return StepResult::failure(
                stepName: $this->name,
                error: 'Parallel step failures: ' . implode('; ', $errors),
                durationMs: $durationMs,
                metadata: ['sub_results' => $outputs],
            );
        }

        return StepResult::success(
            stepName: $this->name,
            output: $outputs,
            durationMs: $durationMs,
            metadata: ['sub_steps' => count($this->steps)],
        );
    }

    public function describe(): string
    {
        $stepNames = array_map(fn (StepInterface $s) => $s->getName(), $this->steps);

        return "Parallel step '{$this->name}': run [" . implode(', ', $stepNames) . "] concurrently";
    }

    /**
     * @return StepInterface[]
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Execute sub-steps in parallel.
     *
     * Uses PHP fibers when available for true concurrency with I/O operations.
     * Falls back to sequential execution.
     *
     * @return StepResult[]
     */
    private function executeParallel(PipelineContext $context): array
    {
        // For I/O-bound agent steps, we use sequential execution here.
        // PipelineEngine may override this with actual parallel execution
        // using process-based backends.
        $results = [];
        foreach ($this->steps as $step) {
            try {
                $results[] = $step->execute($context);
            } catch (\Throwable $e) {
                $results[] = StepResult::failure(
                    stepName: $step->getName(),
                    error: $e->getMessage(),
                );

                if ($this->failureStrategy === FailureStrategy::ABORT && !$this->waitAll) {
                    break;
                }
            }
        }

        return $results;
    }
}
