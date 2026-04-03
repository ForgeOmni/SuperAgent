<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\ApprovalStep;
use SuperAgent\Pipeline\Steps\FailureStrategy;
use SuperAgent\Pipeline\Steps\LoopStep;
use SuperAgent\Pipeline\Steps\ParallelStep;
use SuperAgent\Pipeline\Steps\StepInterface;

/**
 * Executes pipeline definitions with dependency resolution, failure handling,
 * agent execution, and approval gates.
 *
 * Usage:
 *   $config = PipelineConfig::fromYamlFile('pipelines.yaml');
 *   $engine = new PipelineEngine($config);
 *   $engine->setAgentRunner(fn (AgentStep $step, PipelineContext $ctx) => $backend->run($step, $ctx));
 *   $result = $engine->run('code-review', ['files' => ['src/App.php']]);
 */
class PipelineEngine
{
    private PipelineConfig $config;

    private LoggerInterface $logger;

    /**
     * Callback to execute agent steps: fn(AgentStep, PipelineContext): string
     * @var callable|null
     */
    private $agentRunner = null;

    /**
     * Callback for approval gates: fn(ApprovalStep, PipelineContext): bool
     * @var callable|null
     */
    private $approvalHandler = null;

    /**
     * Event listeners: event => [callable, ...]
     * @var array<string, callable[]>
     */
    private array $listeners = [];

    public function __construct(PipelineConfig $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Set the callback that executes agent steps.
     *
     * Signature: fn(AgentStep $step, PipelineContext $context): string
     * Returns the agent's output string.
     */
    public function setAgentRunner(callable $runner): void
    {
        $this->agentRunner = $runner;
    }

    /**
     * Set the callback that handles approval gates.
     *
     * Signature: fn(ApprovalStep $step, PipelineContext $context): bool
     * Returns true if approved, false if denied.
     */
    public function setApprovalHandler(callable $handler): void
    {
        $this->approvalHandler = $handler;
    }

    /**
     * Register an event listener.
     *
     * Events: pipeline.start, pipeline.end, step.start, step.end, step.retry, step.skip, loop.iteration
     */
    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Run a named pipeline with the given inputs.
     *
     * @param array<string, mixed> $inputs
     */
    public function run(string $pipelineName, array $inputs = []): PipelineResult
    {
        $pipeline = $this->config->getPipeline($pipelineName);

        if ($pipeline === null) {
            return PipelineResult::failure(
                $pipelineName,
                "Pipeline '{$pipelineName}' not found",
                [],
                0,
            );
        }

        // Validate and apply default inputs
        $inputErrors = $pipeline->validateInputs($inputs);
        if (!empty($inputErrors)) {
            return PipelineResult::failure(
                $pipelineName,
                'Input validation failed: ' . implode('; ', $inputErrors),
                [],
                0,
            );
        }
        $inputs = $pipeline->applyInputDefaults($inputs);

        $context = new PipelineContext($inputs);
        $start = hrtime(true);

        $this->emit('pipeline.start', [
            'pipeline' => $pipelineName,
            'inputs' => $inputs,
            'steps' => count($pipeline->steps),
        ]);

        $this->logger->info("Pipeline '{$pipelineName}' started", [
            'steps' => count($pipeline->steps),
        ]);

        // Build execution order respecting dependencies
        $orderedSteps = $this->resolveExecutionOrder($pipeline->steps);

        $stepResults = [];
        $failed = false;
        $failError = null;

        foreach ($orderedSteps as $step) {
            if ($context->isCancelled()) {
                $stepResults[] = StepResult::skipped($step->getName(), 'Pipeline cancelled');
                continue;
            }

            // Check if dependencies are satisfied
            if (!$this->areDependenciesMet($step, $context)) {
                $result = StepResult::skipped(
                    $step->getName(),
                    'Dependencies not met: ' . implode(', ', $step->getDependencies()),
                );
                $stepResults[] = $result;
                $context->setStepResult($step->getName(), $result);
                continue;
            }

            $result = $this->executeStep($step, $context);
            $stepResults[] = $result;
            $context->setStepResult($step->getName(), $result);

            if ($result->isFailed()) {
                $this->logger->warning("Step '{$step->getName()}' failed", [
                    'error' => $result->error,
                    'strategy' => $step->getFailureStrategy()->value,
                ]);

                if ($step->getFailureStrategy() === FailureStrategy::ABORT) {
                    $failed = true;
                    $failError = "Step '{$step->getName()}' failed: {$result->error}";
                    // Skip remaining steps
                    $context->cancel();
                }
            }
        }

        $totalDurationMs = (hrtime(true) - $start) / 1_000_000;

        $pipelineResult = $failed
            ? PipelineResult::failure($pipelineName, $failError, $stepResults, $totalDurationMs)
            : PipelineResult::success($pipelineName, $stepResults, $totalDurationMs);

        $this->emit('pipeline.end', [
            'pipeline' => $pipelineName,
            'status' => $pipelineResult->status->value,
            'duration_ms' => $totalDurationMs,
            'summary' => $pipelineResult->getSummary(),
        ]);

        $this->logger->info("Pipeline '{$pipelineName}' completed", $pipelineResult->getSummary());

        return $pipelineResult;
    }

    /**
     * Reload the engine with a new configuration.
     */
    public function reload(PipelineConfig $config): void
    {
        $this->config = $config;
    }

    /**
     * Get available pipeline names.
     *
     * @return string[]
     */
    public function getPipelineNames(): array
    {
        return $this->config->getPipelineNames();
    }

    /**
     * Get a pipeline definition.
     */
    public function getPipeline(string $name): ?PipelineDefinition
    {
        return $this->config->getPipeline($name);
    }

    /**
     * Get statistics about loaded pipelines.
     *
     * @return array{pipelines: int, total_steps: int}
     */
    public function getStatistics(): array
    {
        $totalSteps = 0;
        foreach ($this->config->getPipelines() as $pipeline) {
            $totalSteps += count($pipeline->steps);
        }

        return [
            'pipelines' => count($this->config->getPipelines()),
            'total_steps' => $totalSteps,
        ];
    }

    /**
     * Execute a single step with retry and timeout handling.
     */
    private function executeStep(StepInterface $step, PipelineContext $context): StepResult
    {
        $this->emit('step.start', [
            'step' => $step->getName(),
            'description' => $step->describe(),
        ]);

        $this->logger->debug("Executing step: {$step->getName()}", [
            'type' => $step::class,
            'dependencies' => $step->getDependencies(),
        ]);

        $maxAttempts = 1;
        if ($step instanceof Steps\AbstractStep) {
            $maxAttempts = max(1, $step->getMaxRetries() + 1);
        }

        $result = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = $this->executeStepOnce($step, $context);

            if ($result->isSuccessful() || $result->status === StepStatus::SKIPPED) {
                break;
            }

            // Handle waiting_approval status
            if ($result->status === StepStatus::WAITING_APPROVAL) {
                $result = $this->handleApproval($step, $context, $result);
                break;
            }

            if ($attempt < $maxAttempts && $step->getFailureStrategy() === FailureStrategy::RETRY) {
                $this->emit('step.retry', [
                    'step' => $step->getName(),
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxAttempts,
                    'error' => $result->error,
                ]);

                $this->logger->info("Retrying step '{$step->getName()}'", [
                    'attempt' => $attempt + 1,
                    'error' => $result->error,
                ]);
            }
        }

        $this->emit('step.end', [
            'step' => $step->getName(),
            'status' => $result->status->value,
            'duration_ms' => $result->durationMs,
        ]);

        return $result;
    }

    /**
     * Execute a step once, routing to the appropriate handler.
     */
    private function executeStepOnce(StepInterface $step, PipelineContext $context): StepResult
    {
        try {
            // For conditional steps, evaluate condition then route inner step through engine
            if ($step instanceof Steps\ConditionalStep) {
                return $this->executeConditionalStep($step, $context);
            }

            // For agent steps, use the injected agent runner
            if ($step instanceof AgentStep && $this->agentRunner !== null) {
                return $this->executeAgentStep($step, $context);
            }

            // For parallel steps with agent sub-steps, recursively handle
            if ($step instanceof ParallelStep) {
                return $this->executeParallelStep($step, $context);
            }

            // For loop steps, execute the loop body with engine routing
            if ($step instanceof LoopStep) {
                return $this->executeLoopStep($step, $context);
            }

            // For all other step types, call execute directly
            return $step->execute($context);
        } catch (\Throwable $e) {
            return StepResult::failure(
                stepName: $step->getName(),
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Execute a conditional step: evaluate condition, then route inner step through engine.
     */
    private function executeConditionalStep(Steps\ConditionalStep $step, PipelineContext $context): StepResult
    {
        if (!$step->isConditionMet($context)) {
            return StepResult::skipped($step->getName(), 'Condition not met');
        }

        // Condition met — route inner step through engine for proper agent/parallel handling
        return $this->executeStepOnce($step->getInnerStep(), $context);
    }

    /**
     * Execute an agent step using the injected agent runner.
     */
    private function executeAgentStep(AgentStep $step, PipelineContext $context): StepResult
    {
        $start = hrtime(true);

        try {
            $output = ($this->agentRunner)($step, $context);
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            return StepResult::success(
                stepName: $step->getName(),
                output: $output,
                durationMs: $durationMs,
                metadata: [
                    'agent_type' => $step->getAgentType(),
                ],
            );
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            return StepResult::failure(
                stepName: $step->getName(),
                error: $e->getMessage(),
                durationMs: $durationMs,
            );
        }
    }

    /**
     * Execute a parallel step, routing agent sub-steps through the runner.
     */
    private function executeParallelStep(ParallelStep $step, PipelineContext $context): StepResult
    {
        $start = hrtime(true);
        $outputs = [];
        $errors = [];

        foreach ($step->getSteps() as $subStep) {
            $subResult = $this->executeStepOnce($subStep, $context);
            $context->setStepResult($subStep->getName(), $subResult);
            $outputs[$subStep->getName()] = $subResult->output;

            if ($subResult->isFailed()) {
                $errors[] = "{$subStep->getName()}: {$subResult->error}";
            }
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        if (!empty($errors)) {
            return StepResult::failure(
                stepName: $step->getName(),
                error: 'Parallel step failures: ' . implode('; ', $errors),
                durationMs: $durationMs,
                metadata: ['sub_results' => $outputs],
            );
        }

        return StepResult::success(
            stepName: $step->getName(),
            output: $outputs,
            durationMs: $durationMs,
            metadata: ['sub_steps' => count($step->getSteps())],
        );
    }

    /**
     * Execute a loop step, routing inner agent steps through the engine.
     *
     * Each iteration:
     *   1. Set loop.{name}.iteration / loop.{name}.max in context
     *   2. Execute all body steps via executeStepOnce (proper agent routing)
     *   3. Check exit conditions
     *   4. Emit loop.iteration event
     */
    private function executeLoopStep(LoopStep $step, PipelineContext $context): StepResult
    {
        $start = hrtime(true);
        $iteration = 0;
        $lastBodyResults = [];

        while ($iteration < $step->getMaxIterations()) {
            $iteration++;

            // Set loop iteration variables
            $context->setVariable("loop.{$step->getName()}.iteration", $iteration);
            $context->setVariable("loop.{$step->getName()}.max", $step->getMaxIterations());

            $this->emit('loop.iteration', [
                'loop' => $step->getName(),
                'iteration' => $iteration,
                'max_iterations' => $step->getMaxIterations(),
            ]);

            $this->logger->debug("Loop '{$step->getName()}' iteration {$iteration}/{$step->getMaxIterations()}");

            // Execute each body step through the engine
            $lastBodyResults = [];
            foreach ($step->getBodySteps() as $bodyStep) {
                $bodyResult = $this->executeStepOnce($bodyStep, $context);
                $context->setStepResult($bodyStep->getName(), $bodyResult);
                $lastBodyResults[$bodyStep->getName()] = $bodyResult;

                if ($bodyResult->isFailed() && $step->getFailureStrategy() === FailureStrategy::ABORT) {
                    $durationMs = (hrtime(true) - $start) / 1_000_000;

                    return StepResult::failure(
                        stepName: $step->getName(),
                        error: "Loop iteration {$iteration}: step '{$bodyStep->getName()}' failed: {$bodyResult->error}",
                        durationMs: $durationMs,
                        metadata: [
                            'iterations' => $iteration,
                            'max_iterations' => $step->getMaxIterations(),
                            'exit_reason' => 'step_failed',
                            'body_steps' => count($step->getBodySteps()),
                        ],
                    );
                }
            }

            // Check exit condition after this iteration
            if ($step->isExitConditionMet($context)) {
                $this->logger->info("Loop '{$step->getName()}' exit condition met at iteration {$iteration}");
                break;
            }
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $exitReason = $iteration >= $step->getMaxIterations() ? 'max_iterations' : 'exit_condition';

        $outputs = [];
        foreach ($lastBodyResults as $name => $result) {
            $outputs[$name] = $result->output;
        }

        return StepResult::success(
            stepName: $step->getName(),
            output: $outputs,
            durationMs: $durationMs,
            metadata: [
                'iterations' => $iteration,
                'max_iterations' => $step->getMaxIterations(),
                'exit_reason' => $exitReason,
                'body_steps' => count($step->getBodySteps()),
            ],
        );
    }

    /**
     * Handle an approval gate by invoking the approval handler.
     */
    private function handleApproval(StepInterface $step, PipelineContext $context, StepResult $waitingResult): StepResult
    {
        if (!($step instanceof ApprovalStep)) {
            return $waitingResult;
        }

        if ($this->approvalHandler === null) {
            // No handler configured — auto-approve
            $this->logger->warning("No approval handler configured, auto-approving step '{$step->getName()}'");

            return StepResult::success(
                stepName: $step->getName(),
                output: 'Auto-approved (no handler)',
                metadata: ['auto_approved' => true],
            );
        }

        try {
            $approved = ($this->approvalHandler)($step, $context);

            if ($approved) {
                return StepResult::success(
                    stepName: $step->getName(),
                    output: 'Approved',
                    metadata: ['approved' => true],
                );
            }

            return StepResult::failure(
                stepName: $step->getName(),
                error: 'Approval denied by user',
                metadata: ['denied' => true],
            );
        } catch (\Throwable $e) {
            return StepResult::failure(
                stepName: $step->getName(),
                error: "Approval handler error: {$e->getMessage()}",
            );
        }
    }

    /**
     * Check if all dependencies of a step are satisfied.
     */
    private function areDependenciesMet(StepInterface $step, PipelineContext $context): bool
    {
        foreach ($step->getDependencies() as $dep) {
            if (!$context->isStepCompleted($dep)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve step execution order using topological sort based on dependencies.
     *
     * @param StepInterface[] $steps
     * @return StepInterface[]
     */
    private function resolveExecutionOrder(array $steps): array
    {
        // Build adjacency map
        $stepMap = [];
        foreach ($steps as $step) {
            $stepMap[$step->getName()] = $step;
        }

        // If no dependencies exist, preserve the original order
        $hasDeps = false;
        foreach ($steps as $step) {
            if (!empty($step->getDependencies())) {
                $hasDeps = true;
                break;
            }
        }

        if (!$hasDeps) {
            return $steps;
        }

        // Topological sort (Kahn's algorithm)
        $inDegree = [];
        $adjacency = [];
        foreach ($steps as $step) {
            $name = $step->getName();
            $inDegree[$name] = $inDegree[$name] ?? 0;
            $adjacency[$name] = $adjacency[$name] ?? [];

            foreach ($step->getDependencies() as $dep) {
                if (isset($stepMap[$dep])) {
                    $adjacency[$dep][] = $name;
                    $inDegree[$name] = ($inDegree[$name] ?? 0) + 1;
                }
            }
        }

        $queue = [];
        foreach ($steps as $step) {
            if (($inDegree[$step->getName()] ?? 0) === 0) {
                $queue[] = $step->getName();
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $stepMap[$current];

            foreach ($adjacency[$current] ?? [] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // Detect circular dependencies
        if (count($sorted) !== count($steps)) {
            $this->logger->error('Circular dependency detected in pipeline steps');
            // Fall back to original order
            return $steps;
        }

        return $sorted;
    }

    /**
     * Emit an event to all registered listeners.
     */
    private function emit(string $event, array $data = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($data);
            } catch (\Throwable $e) {
                $this->logger->warning("Event listener error for '{$event}'", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
