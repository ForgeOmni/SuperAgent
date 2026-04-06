<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\SelfHealing;

final class SelfHealingStrategy
{
    private DiagnosticAgent $diagnosticAgent;
    private StepMutator $mutator;
    private array $healingHistory = [];

    public function __construct(
        ?DiagnosticAgent $diagnosticAgent = null,
        private readonly array $config = [],
    ) {
        $maxHealAttempts = $config['max_heal_attempts'] ?? 3;
        $diagnoseModel = $config['diagnose_model'] ?? 'sonnet';
        $maxDiagnoseBudget = $config['max_diagnose_budget'] ?? 0.50;
        $allowedMutations = $config['allowed_mutations'] ?? [];

        $this->diagnosticAgent = $diagnosticAgent ?? new DiagnosticAgent(
            model: $diagnoseModel,
            maxBudget: $maxDiagnoseBudget,
        );
        $this->mutator = new StepMutator($allowedMutations);
    }

    /**
     * Set the agent runner for the diagnostic agent.
     */
    public function setAgentRunner(callable $runner): void
    {
        $this->diagnosticAgent->setAgentRunner($runner);
    }

    /**
     * Attempt to heal a failed step.
     *
     * @param callable $retryCallback fn(array $mutatedConfig): mixed - re-executes the step
     */
    public function heal(StepFailure $failure, callable $retryCallback): HealingResult
    {
        $maxAttempts = $this->config['max_heal_attempts'] ?? 3;
        $startTime = microtime(true);
        $diagnoses = [];
        $plans = [];
        $totalCost = 0.0;
        $previousErrors = $failure->previousAttempts;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Diagnose the failure
            $diagnosis = $this->diagnosticAgent->diagnose($failure);
            $diagnoses[] = $diagnosis;

            if (!$diagnosis->isHealable) {
                $duration = (microtime(true) - $startTime) * 1000;
                $result = new HealingResult(
                    healed: false,
                    attemptsUsed: $attempt,
                    diagnoses: $diagnoses,
                    plansAttempted: $plans,
                    healingCost: $totalCost,
                    totalDurationMs: $duration,
                    summary: "Failure diagnosed as unhealable: {$diagnosis->rootCause}",
                );
                $this->recordHistory($failure->stepName, $result);
                return $result;
            }

            // Create healing plan
            $plan = $this->diagnosticAgent->planHealing($diagnosis, [
                'allowed_mutations' => $this->config['allowed_mutations'] ?? [],
            ]);
            $plans[] = $plan;
            $totalCost += $plan->estimatedAdditionalCost;

            // Apply mutations
            $mutatedConfig = $this->mutator->applyMutations($failure->stepConfig, $plan);

            // Retry with mutated config
            try {
                $stepResult = $retryCallback($mutatedConfig);

                $duration = (microtime(true) - $startTime) * 1000;
                $result = new HealingResult(
                    healed: true,
                    attemptsUsed: $attempt,
                    diagnoses: $diagnoses,
                    plansAttempted: $plans,
                    result: $stepResult,
                    healingCost: $totalCost,
                    totalDurationMs: $duration,
                    summary: "Healed after {$attempt} attempt(s) using strategy: {$plan->strategy}",
                );
                $this->recordHistory($failure->stepName, $result);
                return $result;
            } catch (\Throwable $e) {
                $previousErrors[] = $e->getMessage();

                // Create new failure for next iteration
                $failure = new StepFailure(
                    stepName: $failure->stepName,
                    stepType: $failure->stepType,
                    stepConfig: $mutatedConfig,
                    errorMessage: $e->getMessage(),
                    errorClass: get_class($e),
                    stackTrace: $e->getTraceAsString(),
                    attemptNumber: $failure->attemptNumber + $attempt,
                    previousAttempts: $previousErrors,
                    contextSnapshot: $failure->contextSnapshot,
                );
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $result = new HealingResult(
            healed: false,
            attemptsUsed: $maxAttempts,
            diagnoses: $diagnoses,
            plansAttempted: $plans,
            healingCost: $totalCost,
            totalDurationMs: $duration,
            summary: "Failed to heal after {$maxAttempts} attempts",
        );
        $this->recordHistory($failure->stepName, $result);
        return $result;
    }

    /**
     * Check if a failure is potentially healable.
     */
    public function canHeal(StepFailure $failure): bool
    {
        if (!$failure->isRecoverable()) {
            return false;
        }

        $maxAttempts = $this->config['max_heal_attempts'] ?? 3;
        if ($failure->attemptNumber >= $maxAttempts) {
            return false;
        }

        return true;
    }

    /**
     * Get healing history for this pipeline run.
     */
    public function getHealingHistory(): array
    {
        return $this->healingHistory;
    }

    private function recordHistory(string $stepName, HealingResult $result): void
    {
        $this->healingHistory[] = [
            'step_name' => $stepName,
            'healed' => $result->healed,
            'attempts' => $result->attemptsUsed,
            'cost' => $result->healingCost,
            'timestamp' => date('c'),
        ];
    }
}
