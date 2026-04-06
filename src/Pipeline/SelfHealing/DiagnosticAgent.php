<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\SelfHealing;

final class DiagnosticAgent
{
    /**
     * @var callable Agent runner: fn(string $prompt, string $model, ?string $systemPrompt, int $maxTurns, float $maxBudget): array{content: string, cost: float}
     */
    private $agentRunner;

    public function __construct(
        private readonly string $model = 'sonnet',
        private readonly float $maxBudget = 0.50,
        ?callable $agentRunner = null,
    ) {
        $this->agentRunner = $agentRunner ?? fn() => ['content' => '', 'cost' => 0.0];
    }

    /**
     * Set the agent runner callback.
     */
    public function setAgentRunner(callable $runner): void
    {
        $this->agentRunner = $runner;
    }

    /**
     * Diagnose why a step failed.
     */
    public function diagnose(StepFailure $failure): Diagnosis
    {
        // First try rule-based diagnosis (fast, free)
        $ruleBased = $this->ruleBasedDiagnosis($failure);
        if ($ruleBased->confidence >= 0.8) {
            return $ruleBased;
        }

        // Fall back to LLM-based diagnosis
        return $this->llmDiagnosis($failure, $ruleBased);
    }

    /**
     * Generate a healing plan based on diagnosis.
     */
    public function planHealing(Diagnosis $diagnosis, array $constraints = []): HealingPlan
    {
        $allowedMutations = $constraints['allowed_mutations'] ?? [
            HealingPlan::STRATEGY_MODIFY_PROMPT,
            HealingPlan::STRATEGY_CHANGE_MODEL,
            HealingPlan::STRATEGY_ADJUST_TIMEOUT,
            HealingPlan::STRATEGY_ADD_CONTEXT,
            HealingPlan::STRATEGY_SIMPLIFY_TASK,
        ];

        // Map diagnosis category to healing strategy
        return match ($diagnosis->category) {
            Diagnosis::CATEGORY_TIMEOUT => $this->planForTimeout($diagnosis, $allowedMutations),
            Diagnosis::CATEGORY_MODEL_LIMITATION => $this->planForModelLimitation($diagnosis, $allowedMutations),
            Diagnosis::CATEGORY_RESOURCE_EXHAUSTION => $this->planForResourceExhaustion($diagnosis, $allowedMutations),
            Diagnosis::CATEGORY_INPUT_ERROR => $this->planForInputError($diagnosis, $allowedMutations),
            Diagnosis::CATEGORY_EXTERNAL_DEPENDENCY => $this->planForExternalDep($diagnosis, $allowedMutations),
            Diagnosis::CATEGORY_TOOL_FAILURE => $this->planForToolFailure($diagnosis, $allowedMutations),
            default => $this->planGeneric($diagnosis, $allowedMutations),
        };
    }

    private function ruleBasedDiagnosis(StepFailure $failure): Diagnosis
    {
        $category = $failure->getErrorCategory();
        $error = $failure->errorMessage;

        return match ($category) {
            'timeout' => new Diagnosis(
                rootCause: 'Step execution exceeded timeout limit',
                category: Diagnosis::CATEGORY_TIMEOUT,
                confidence: 0.9,
                suggestedFixes: ['Increase timeout', 'Simplify the task', 'Split into smaller steps'],
                isHealable: true,
                reasoning: "Error message indicates a timeout: {$error}",
            ),
            'rate_limit' => new Diagnosis(
                rootCause: 'API rate limit exceeded',
                category: Diagnosis::CATEGORY_EXTERNAL_DEPENDENCY,
                confidence: 0.95,
                suggestedFixes: ['Wait and retry', 'Switch to a different model', 'Reduce parallel calls'],
                isHealable: true,
                reasoning: "Rate limit error detected. Simple retry with backoff should resolve.",
            ),
            'resource_exhaustion' => new Diagnosis(
                rootCause: 'Resource limits exceeded (memory/context)',
                category: Diagnosis::CATEGORY_RESOURCE_EXHAUSTION,
                confidence: 0.85,
                suggestedFixes: ['Simplify the task', 'Use a model with larger context', 'Split the step'],
                isHealable: true,
                reasoning: "Resource exhaustion detected: {$error}",
            ),
            'model_limitation' => new Diagnosis(
                rootCause: 'Model context or capability limit reached',
                category: Diagnosis::CATEGORY_MODEL_LIMITATION,
                confidence: 0.85,
                suggestedFixes: ['Upgrade to a more capable model', 'Reduce input size', 'Simplify prompt'],
                isHealable: true,
                reasoning: "Model limitation detected: {$error}",
            ),
            'external_dependency' => new Diagnosis(
                rootCause: 'External service connection failure',
                category: Diagnosis::CATEGORY_EXTERNAL_DEPENDENCY,
                confidence: 0.8,
                suggestedFixes: ['Retry with backoff', 'Check connectivity', 'Use fallback'],
                isHealable: true,
                reasoning: "Connection/network error: {$error}",
            ),
            default => new Diagnosis(
                rootCause: 'Unknown failure',
                category: Diagnosis::CATEGORY_UNKNOWN,
                confidence: 0.3,
                suggestedFixes: ['Modify prompt with more context', 'Try a different model'],
                isHealable: $failure->isRecoverable(),
                reasoning: "Could not determine root cause from error: {$error}",
            ),
        };
    }

    private function llmDiagnosis(StepFailure $failure, Diagnosis $ruleBased): Diagnosis
    {
        $prompt = $this->buildDiagnosticPrompt($failure);
        $systemPrompt = 'You are a diagnostic expert analyzing pipeline step failures. Be concise and precise. Respond in JSON format with keys: root_cause, category, suggested_fixes (array), is_healable (bool), reasoning.';

        try {
            $result = ($this->agentRunner)(
                $prompt,
                $this->model,
                $systemPrompt,
                1,
                $this->maxBudget / 2,
            );

            $parsed = $this->parseDiagnosticResponse($result['content'] ?? '');
            if ($parsed !== null) {
                return $parsed;
            }
        } catch (\Throwable) {
            // Fall back to rule-based
        }

        return $ruleBased;
    }

    private function buildDiagnosticPrompt(StepFailure $failure): string
    {
        $previousAttempts = '';
        if (!empty($failure->previousAttempts)) {
            $previousAttempts = "Previous attempts:\n" . implode("\n", array_map(
                fn($a, $i) => "  Attempt " . ($i + 1) . ": " . $a,
                $failure->previousAttempts,
                array_keys($failure->previousAttempts),
            ));
        }

        return <<<PROMPT
A pipeline step failed. Diagnose the root cause.

Step: {$failure->stepName} (type: {$failure->stepType})
Error: {$failure->errorMessage}
Error class: {$failure->errorClass}
Attempt: {$failure->attemptNumber}
{$previousAttempts}

Step config (relevant parts):
```json
{$this->formatConfig($failure->stepConfig)}
```

Categories: input_error, model_limitation, tool_failure, timeout, resource_exhaustion, logic_error, external_dependency, unknown

Respond in JSON.
PROMPT;
    }

    private function formatConfig(array $config): string
    {
        $relevant = array_intersect_key($config, array_flip(['prompt', 'model', 'timeout', 'max_turns']));
        return json_encode($relevant, JSON_PRETTY_PRINT) ?: '{}';
    }

    private function parseDiagnosticResponse(string $content): ?Diagnosis
    {
        // Extract JSON from response
        if (preg_match('/\{[^{}]*"root_cause"[^{}]*\}/s', $content, $match)) {
            $data = json_decode($match[0], true);
            if ($data !== null && isset($data['root_cause'])) {
                $validCategories = [
                    Diagnosis::CATEGORY_INPUT_ERROR, Diagnosis::CATEGORY_MODEL_LIMITATION,
                    Diagnosis::CATEGORY_TOOL_FAILURE, Diagnosis::CATEGORY_TIMEOUT,
                    Diagnosis::CATEGORY_RESOURCE_EXHAUSTION, Diagnosis::CATEGORY_LOGIC_ERROR,
                    Diagnosis::CATEGORY_EXTERNAL_DEPENDENCY, Diagnosis::CATEGORY_UNKNOWN,
                ];

                $category = in_array($data['category'] ?? '', $validCategories)
                    ? $data['category']
                    : Diagnosis::CATEGORY_UNKNOWN;

                return new Diagnosis(
                    rootCause: $data['root_cause'],
                    category: $category,
                    confidence: 0.7,
                    suggestedFixes: (array) ($data['suggested_fixes'] ?? []),
                    isHealable: (bool) ($data['is_healable'] ?? true),
                    reasoning: $data['reasoning'] ?? '',
                );
            }
        }

        return null;
    }

    private function planForTimeout(Diagnosis $diagnosis, array $allowed): HealingPlan
    {
        $mutations = [];

        if (in_array(HealingPlan::STRATEGY_ADJUST_TIMEOUT, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_ADJUST_TIMEOUT, 'value' => 300];
        }
        if (in_array(HealingPlan::STRATEGY_SIMPLIFY_TASK, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_SIMPLIFY_TASK, 'value' => true];
        }

        return new HealingPlan(
            strategy: HealingPlan::STRATEGY_ADJUST_TIMEOUT,
            mutations: $mutations,
            rationale: 'Increase timeout and simplify task to avoid exceeding time limits',
            estimatedSuccessRate: 0.7,
            estimatedAdditionalCost: 0.0,
        );
    }

    private function planForModelLimitation(Diagnosis $diagnosis, array $allowed): HealingPlan
    {
        $mutations = [];

        if (in_array(HealingPlan::STRATEGY_CHANGE_MODEL, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_CHANGE_MODEL, 'value' => 'opus'];
        }
        if (in_array(HealingPlan::STRATEGY_SIMPLIFY_TASK, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_SIMPLIFY_TASK, 'value' => true];
        }

        return new HealingPlan(
            strategy: HealingPlan::STRATEGY_CHANGE_MODEL,
            mutations: $mutations,
            rationale: 'Upgrade to more capable model to handle complex task',
            estimatedSuccessRate: 0.75,
            estimatedAdditionalCost: 0.50,
        );
    }

    private function planForResourceExhaustion(Diagnosis $diagnosis, array $allowed): HealingPlan
    {
        $mutations = [];

        if (in_array(HealingPlan::STRATEGY_SIMPLIFY_TASK, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_SIMPLIFY_TASK, 'value' => true];
        }
        if (in_array(HealingPlan::STRATEGY_MODIFY_PROMPT, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_MODIFY_PROMPT, 'value' => 'Use concise outputs. Avoid including full file contents in responses.'];
        }

        return new HealingPlan(
            strategy: HealingPlan::STRATEGY_SIMPLIFY_TASK,
            mutations: $mutations,
            rationale: 'Reduce resource consumption by simplifying the task',
            estimatedSuccessRate: 0.6,
            estimatedAdditionalCost: 0.0,
        );
    }

    private function planForInputError(Diagnosis $diagnosis, array $allowed): HealingPlan
    {
        $mutations = [];

        if (in_array(HealingPlan::STRATEGY_ADD_CONTEXT, $allowed)) {
            $context = "Previous attempt failed with: " . $diagnosis->rootCause;
            $mutations[] = ['type' => HealingPlan::STRATEGY_ADD_CONTEXT, 'value' => $context];
        }
        if (in_array(HealingPlan::STRATEGY_MODIFY_PROMPT, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_MODIFY_PROMPT, 'value' => 'Verify all inputs and paths exist before proceeding. Handle missing data gracefully.'];
        }

        return new HealingPlan(
            strategy: HealingPlan::STRATEGY_ADD_CONTEXT,
            mutations: $mutations,
            rationale: 'Add error context to help agent avoid same mistake',
            estimatedSuccessRate: 0.65,
            estimatedAdditionalCost: 0.10,
        );
    }

    private function planForExternalDep(Diagnosis $diagnosis, array $allowed): HealingPlan
    {
        // For external dependencies, simple retry is usually best
        return new HealingPlan(
            strategy: HealingPlan::STRATEGY_ADJUST_TIMEOUT,
            mutations: [
                ['type' => HealingPlan::STRATEGY_ADJUST_TIMEOUT, 'value' => 180],
            ],
            rationale: 'Retry with increased timeout for transient external dependency failure',
            estimatedSuccessRate: 0.8,
            estimatedAdditionalCost: 0.0,
        );
    }

    private function planForToolFailure(Diagnosis $diagnosis, array $allowed): HealingPlan
    {
        $mutations = [];

        if (in_array(HealingPlan::STRATEGY_MODIFY_PROMPT, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_MODIFY_PROMPT, 'value' => 'A tool call failed previously. Try an alternative approach that does not rely on the same tool.'];
        }
        if (in_array(HealingPlan::STRATEGY_ADD_CONTEXT, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_ADD_CONTEXT, 'value' => 'Tool failure: ' . $diagnosis->rootCause];
        }

        return new HealingPlan(
            strategy: HealingPlan::STRATEGY_MODIFY_PROMPT,
            mutations: $mutations,
            rationale: 'Guide agent to use alternative approaches after tool failure',
            estimatedSuccessRate: 0.55,
            estimatedAdditionalCost: 0.15,
        );
    }

    private function planGeneric(Diagnosis $diagnosis, array $allowed): HealingPlan
    {
        $mutations = [];

        if (in_array(HealingPlan::STRATEGY_ADD_CONTEXT, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_ADD_CONTEXT, 'value' => 'Previous attempt failed: ' . $diagnosis->rootCause];
        }
        if (in_array(HealingPlan::STRATEGY_MODIFY_PROMPT, $allowed)) {
            $mutations[] = ['type' => HealingPlan::STRATEGY_MODIFY_PROMPT, 'value' => 'Be careful and methodical. Double-check your approach before executing.'];
        }

        return new HealingPlan(
            strategy: HealingPlan::STRATEGY_ADD_CONTEXT,
            mutations: $mutations,
            rationale: 'Add failure context and encourage more careful approach',
            estimatedSuccessRate: 0.4,
            estimatedAdditionalCost: 0.10,
        );
    }
}
