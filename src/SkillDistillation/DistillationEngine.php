<?php

declare(strict_types=1);

namespace SuperAgent\SkillDistillation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Converts an ExecutionTrace into a reusable DistilledSkill.
 *
 * The distillation process:
 *   1. Analyze the tool call sequence to extract a repeatable pattern
 *   2. Generalize specific values (file paths, variables) into template parameters
 *   3. Select an appropriate target model tier (cheaper than the source)
 *   4. Generate a Markdown skill template with step-by-step instructions
 *   5. Estimate cost savings
 *
 * The generated skill can then be executed by a cheaper model (e.g., Haiku)
 * following the explicit tool call recipe, instead of requiring an expensive
 * model (e.g., Opus) to figure out the approach from scratch.
 */
class DistillationEngine
{
    /** Model cost tiers: model prefix → relative cost (higher = more expensive) */
    private const MODEL_COST_TIERS = [
        'claude-opus' => 100,
        'claude-sonnet' => 30,
        'claude-haiku' => 5,
        'gpt-4o' => 25,
        'gpt-4o-mini' => 3,
        'gpt-4-turbo' => 60,
        'gpt-4' => 80,
        'gpt-3.5' => 2,
    ];

    /** Model downgrade map: source prefix → recommended target */
    private const MODEL_DOWNGRADE_MAP = [
        'claude-opus' => 'claude-sonnet-4-20250514',
        'claude-sonnet' => 'claude-haiku-4-5-20251001',
        'gpt-4o' => 'gpt-4o-mini',
        'gpt-4-turbo' => 'gpt-4o-mini',
        'gpt-4' => 'gpt-4o-mini',
    ];

    private DistillationStore $store;

    private LoggerInterface $logger;

    /** Minimum tool calls for a trace to be worth distilling */
    private int $minSteps;

    /** Minimum cost (USD) for a trace to be worth distilling */
    private float $minCostUsd;

    public function __construct(
        DistillationStore $store,
        int $minSteps = 3,
        float $minCostUsd = 0.01,
        ?LoggerInterface $logger = null,
    ) {
        $this->store = $store;
        $this->minSteps = $minSteps;
        $this->minCostUsd = $minCostUsd;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Distill an execution trace into a reusable skill.
     *
     * Returns null if the trace is too simple to be worth distilling.
     */
    public function distill(ExecutionTrace $trace, ?string $skillName = null): ?DistilledSkill
    {
        // Check if the trace is worth distilling
        if (!$this->isWorthDistilling($trace)) {
            $this->logger->debug('SkillDistillation: trace not worth distilling', [
                'steps' => count($trace->toolCalls),
                'cost' => $trace->costUsd,
            ]);

            return null;
        }

        $name = $skillName ?? $this->generateSkillName($trace->originalPrompt);
        $id = DistilledSkill::generateId($name);

        // Check if already distilled
        if ($this->store->get($id) !== null) {
            $this->logger->debug("SkillDistillation: skill '{$name}' already exists");

            return null;
        }

        $targetModel = $this->selectTargetModel($trace->model);
        $parameters = $this->detectParameters($trace);
        $template = $this->generateTemplate($trace, $name, $targetModel, $parameters);
        $savingsPct = $this->estimateSavings($trace->model, $targetModel);

        $skill = new DistilledSkill(
            id: $id,
            name: $name,
            description: $this->generateDescription($trace),
            category: 'distilled',
            sourceModel: $trace->model,
            targetModel: $targetModel,
            requiredTools: $trace->getUsedTools(),
            template: $template,
            parameters: $parameters,
            sourceSteps: count($trace->toolCalls),
            sourceCostUsd: $trace->costUsd,
            estimatedSavingsPct: $savingsPct,
            createdAt: date('c'),
            metadata: [
                'original_prompt' => $trace->originalPrompt,
                'source_turns' => $trace->turns,
                'source_input_tokens' => $trace->inputTokens,
                'source_output_tokens' => $trace->outputTokens,
            ],
        );

        $this->store->save($skill);

        $this->logger->info("SkillDistillation: distilled '{$name}'", [
            'source_model' => $trace->model,
            'target_model' => $targetModel,
            'steps' => count($trace->toolCalls),
            'savings_pct' => round($savingsPct),
        ]);

        return $skill;
    }

    /**
     * Check if a trace is complex enough to be worth distilling.
     */
    public function isWorthDistilling(ExecutionTrace $trace): bool
    {
        // Must have enough tool calls
        if (count($trace->toolCalls) < $this->minSteps) {
            return false;
        }

        // Must have cost something (skip free/local models)
        if ($trace->costUsd < $this->minCostUsd) {
            return false;
        }

        // Must not have errors
        foreach ($trace->toolCalls as $tc) {
            if ($tc->isError) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the distillation store.
     */
    public function getStore(): DistillationStore
    {
        return $this->store;
    }

    /**
     * Generate a slug-style skill name from the user's prompt.
     */
    private function generateSkillName(string $prompt): string
    {
        // Take first 60 chars, lowercase, replace non-alnum with hyphens
        $name = strtolower(substr($prompt, 0, 60));
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim($name, '-');

        return $name ?: 'distilled-skill';
    }

    /**
     * Generate a description from the trace.
     */
    private function generateDescription(ExecutionTrace $trace): string
    {
        $tools = implode(', ', $trace->getUsedTools());
        $steps = count($trace->toolCalls);

        return "Auto-distilled from {$trace->model} ({$steps} steps using {$tools})";
    }

    /**
     * Select a cheaper target model for replay.
     */
    private function selectTargetModel(string $sourceModel): string
    {
        foreach (self::MODEL_DOWNGRADE_MAP as $prefix => $target) {
            if (str_starts_with($sourceModel, $prefix)) {
                return $target;
            }
        }

        // If no downgrade available, return the same model
        return $sourceModel;
    }

    /**
     * Estimate cost savings percentage when using the target model.
     */
    private function estimateSavings(string $sourceModel, string $targetModel): float
    {
        $sourceCost = $this->getModelCostTier($sourceModel);
        $targetCost = $this->getModelCostTier($targetModel);

        if ($sourceCost <= 0) {
            return 0.0;
        }

        return max(0, (1 - $targetCost / $sourceCost) * 100);
    }

    /**
     * Get relative cost tier for a model.
     */
    private function getModelCostTier(string $model): float
    {
        foreach (self::MODEL_COST_TIERS as $prefix => $cost) {
            if (str_starts_with($model, $prefix)) {
                return $cost;
            }
        }

        return 10; // Default mid-range
    }

    /**
     * Detect generalizable parameters from the trace.
     *
     * Looks for file paths, patterns, and repeated values that could
     * be parameterized in the template.
     *
     * @return string[]
     */
    private function detectParameters(ExecutionTrace $trace): array
    {
        $params = [];

        // Detect file paths → {{target_file}} or {{target_path}}
        $filePaths = [];
        foreach ($trace->toolCalls as $tc) {
            $path = $tc->toolInput['file_path'] ?? $tc->toolInput['path'] ?? null;
            if ($path !== null) {
                $filePaths[] = $path;
            }
        }
        if (!empty($filePaths)) {
            $params[] = 'target_file';
        }

        // Detect bash commands → {{command}}
        foreach ($trace->toolCalls as $tc) {
            if ($tc->toolName === 'Bash') {
                $params[] = 'command';
                break;
            }
        }

        // Detect search patterns → {{search_pattern}}
        foreach ($trace->toolCalls as $tc) {
            if (in_array($tc->toolName, ['Grep', 'Glob'], true)) {
                $params[] = 'search_pattern';
                break;
            }
        }

        // Always include the task description parameter
        if (!in_array('task_description', $params, true)) {
            $params[] = 'task_description';
        }

        return array_unique($params);
    }

    /**
     * Generate the Markdown skill template from the trace.
     */
    private function generateTemplate(
        ExecutionTrace $trace,
        string $name,
        string $targetModel,
        array $parameters,
    ): string {
        $tools = implode(', ', $trace->getUsedTools());
        $paramList = implode(', ', array_map(fn ($p) => "`{$p}`", $parameters));

        // Build frontmatter
        $frontmatter = "---\n";
        $frontmatter .= "name: {$name}\n";
        $frontmatter .= "description: \"Auto-distilled skill from {$trace->model}\"\n";
        $frontmatter .= "category: distilled\n";
        $frontmatter .= "model: {$targetModel}\n";
        $frontmatter .= "source_model: {$trace->model}\n";
        $frontmatter .= "source_cost_usd: {$trace->costUsd}\n";
        $frontmatter .= "---\n\n";

        // Build body: step-by-step instructions
        $body = "# {$name}\n\n";
        $body .= "This skill was auto-distilled from a successful execution by `{$trace->model}`.\n";
        $body .= "Follow these steps exactly using the tools: {$tools}.\n\n";
        $body .= "## Task\n\n";
        $body .= "\$ARGUMENTS\n\n";
        $body .= "## Execution Plan\n\n";
        $body .= "Follow these steps in order:\n\n";

        $stepNum = 0;
        foreach ($trace->toolCalls as $tc) {
            $stepNum++;
            $inputSummary = $tc->summarizeInput();
            $body .= "### Step {$stepNum}: {$tc->toolName}\n\n";

            switch ($tc->toolName) {
                case 'Read':
                    $body .= "Read the file to understand its contents.\n";
                    $body .= "- File: `{$inputSummary}`\n\n";
                    break;
                case 'Glob':
                    $body .= "Find files matching the pattern.\n";
                    $body .= "- Pattern: `{$inputSummary}`\n\n";
                    break;
                case 'Grep':
                    $body .= "Search for the pattern in the codebase.\n";
                    $body .= "- Pattern: `{$inputSummary}`\n\n";
                    break;
                case 'Edit':
                    $body .= "Edit the file to apply the required changes.\n";
                    $body .= "- File: `{$inputSummary}`\n\n";
                    break;
                case 'Write':
                    $body .= "Write the file with the new content.\n";
                    $body .= "- File: `{$inputSummary}`\n\n";
                    break;
                case 'Bash':
                    $body .= "Run the command.\n";
                    $body .= "- Command: `{$inputSummary}`\n\n";
                    break;
                default:
                    $body .= "Use `{$tc->toolName}` tool.\n";
                    if (!empty($inputSummary)) {
                        $body .= "- Input: `{$inputSummary}`\n";
                    }
                    $body .= "\n";
                    break;
            }
        }

        $body .= "## Parameters\n\n";
        $body .= "Available parameters: {$paramList}\n\n";
        $body .= "## Notes\n\n";
        $body .= "- Original execution: {$trace->turns} turns, "
            . count($trace->toolCalls) . " tool calls\n";
        $body .= "- Original cost: \${$trace->costUsd}\n";
        $body .= "- Target model: `{$targetModel}` for cost savings\n";

        return $frontmatter . $body;
    }
}
