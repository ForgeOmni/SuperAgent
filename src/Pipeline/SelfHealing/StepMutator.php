<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\SelfHealing;

final class StepMutator
{
    private array $allowedMutations;

    public function __construct(array $allowedMutations = [])
    {
        $this->allowedMutations = $allowedMutations ?: [
            HealingPlan::STRATEGY_MODIFY_PROMPT,
            HealingPlan::STRATEGY_CHANGE_MODEL,
            HealingPlan::STRATEGY_ADJUST_TIMEOUT,
            HealingPlan::STRATEGY_ADD_CONTEXT,
            HealingPlan::STRATEGY_SIMPLIFY_TASK,
        ];
    }

    /**
     * Apply all mutations from a healing plan to a step config.
     */
    public function applyMutations(array $stepConfig, HealingPlan $plan): array
    {
        foreach ($plan->getMutations() as $mutation) {
            $type = $mutation['type'] ?? '';
            $value = $mutation['value'] ?? null;

            if (!in_array($type, $this->allowedMutations, true)) {
                continue;
            }

            $stepConfig = match ($type) {
                HealingPlan::STRATEGY_MODIFY_PROMPT => $this->modifyPrompt($stepConfig, (string) $value),
                HealingPlan::STRATEGY_CHANGE_MODEL => $this->changeModel($stepConfig, (string) $value),
                HealingPlan::STRATEGY_ADJUST_TIMEOUT => $this->adjustTimeout($stepConfig, (int) $value),
                HealingPlan::STRATEGY_ADD_CONTEXT => $this->addContext($stepConfig, (string) $value),
                HealingPlan::STRATEGY_SIMPLIFY_TASK => $this->simplifyTask($stepConfig),
                default => $stepConfig,
            };
        }

        return $stepConfig;
    }

    public function modifyPrompt(array $config, string $modification): array
    {
        $currentPrompt = $config['prompt'] ?? '';
        $config['prompt'] = $currentPrompt . "\n\nAdditional instruction: " . $modification;
        return $config;
    }

    public function changeModel(array $config, string $model): array
    {
        $config['model'] = $model;
        return $config;
    }

    public function adjustTimeout(array $config, int $seconds): array
    {
        $config['timeout'] = $seconds;
        return $config;
    }

    public function addContext(array $config, string $context): array
    {
        $currentPrompt = $config['prompt'] ?? '';
        $config['prompt'] = "Context from previous attempt:\n{$context}\n\n" . $currentPrompt;
        return $config;
    }

    public function simplifyTask(array $config): array
    {
        $prompt = $config['prompt'] ?? '';

        // Reduce max_turns if set
        if (isset($config['max_turns'])) {
            $config['max_turns'] = max(1, (int) ($config['max_turns'] * 0.7));
        }

        // Add simplification instruction
        $config['prompt'] = $prompt . "\n\nIMPORTANT: Simplify your approach. Focus on the core requirement only. Avoid complex solutions.";

        return $config;
    }

    /**
     * Split a step into multiple simpler sub-steps.
     *
     * @return array[] Array of step configs
     */
    public function splitStep(array $config): array
    {
        $prompt = $config['prompt'] ?? '';

        // Create analysis step + execution step
        $analysisConfig = $config;
        $analysisConfig['name'] = ($config['name'] ?? 'step') . '_analysis';
        $analysisConfig['prompt'] = "Analyze the following task and create a step-by-step plan. Do NOT execute yet, just plan:\n\n" . $prompt;

        $executionConfig = $config;
        $executionConfig['name'] = ($config['name'] ?? 'step') . '_execution';
        $executionConfig['prompt'] = "Execute the following task using the plan from the analysis step:\n\n" . $prompt;

        return [$analysisConfig, $executionConfig];
    }
}
