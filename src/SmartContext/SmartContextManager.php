<?php

declare(strict_types=1);

namespace SuperAgent\SmartContext;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dynamically allocates tokens between thinking and context based on task complexity.
 *
 * Simple tasks get a broad context window (more history preserved).
 * Complex tasks get a deep thinking budget (more reasoning, aggressive compaction).
 *
 * Priority control:
 *   1. Per-task override: options['context_strategy'] = 'deep_thinking' (highest)
 *   2. Config toggle: config('superagent.smart_context.enabled')
 *
 * Usage:
 *   $manager = new SmartContextManager(totalBudget: 100_000);
 *   $allocation = $manager->allocate('Refactor the auth module to use OAuth2');
 *   // → strategy=deep_thinking, thinking=60K, context=40K, keep_recent=4
 *
 *   $allocation = $manager->allocate('Show me the contents of config.php');
 *   // → strategy=broad_context, thinking=15K, context=85K, keep_recent=16
 */
class SmartContextManager
{
    private LoggerInterface $logger;

    /** Per-task strategy override: null = auto-detect from prompt */
    private ?ContextStrategy $forceStrategy = null;

    /** Config-level toggle */
    private bool $configEnabled;

    public function __construct(
        private readonly int $totalBudgetTokens = 100_000,
        private readonly int $minThinkingBudget = 5_000,
        private readonly int $maxThinkingBudget = 128_000,
        bool $configEnabled = true,
        ?LoggerInterface $logger = null,
    ) {
        $this->configEnabled = $configEnabled;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Set per-task strategy override. Takes precedence over auto-detection.
     *
     * @param ContextStrategy|string|null $strategy Strategy or strategy name, null = auto
     */
    public function setForceStrategy(ContextStrategy|string|null $strategy): void
    {
        if (is_string($strategy)) {
            $this->forceStrategy = ContextStrategy::tryFrom($strategy);
        } else {
            $this->forceStrategy = $strategy;
        }
    }

    /**
     * Whether smart context allocation is active.
     */
    public function isEnabled(): bool
    {
        return $this->configEnabled || $this->forceStrategy !== null;
    }

    /**
     * Allocate tokens between thinking and context for a given prompt.
     */
    public function allocate(string $prompt): BudgetAllocation
    {
        // Determine strategy: per-task override > auto-detection
        if ($this->forceStrategy !== null) {
            $complexity = new TaskComplexity(0.5, $this->forceStrategy, ['forced']);
        } else {
            $complexity = TaskComplexity::analyze($prompt);
        }

        $strategy = $complexity->strategy;
        $thinkingRatio = $strategy->thinkingRatio();

        // Calculate budgets
        $thinkingBudget = (int) ($this->totalBudgetTokens * $thinkingRatio);
        $thinkingBudget = max($this->minThinkingBudget, min($this->maxThinkingBudget, $thinkingBudget));
        $contextBudget = $this->totalBudgetTokens - $thinkingBudget;

        $allocation = new BudgetAllocation(
            strategy: $strategy,
            thinkingBudgetTokens: $thinkingBudget,
            contextBudgetTokens: $contextBudget,
            compactionKeepRecent: $strategy->compactionKeepRecent(),
            complexityScore: $complexity->score,
            totalBudgetTokens: $this->totalBudgetTokens,
            signals: $complexity->signals,
        );

        $this->logger->debug("SmartContext: {$allocation->describe()}", [
            'prompt_length' => strlen($prompt),
            'signals' => $complexity->signals,
        ]);

        return $allocation;
    }

    /**
     * Get the total budget.
     */
    public function getTotalBudgetTokens(): int
    {
        return $this->totalBudgetTokens;
    }
}
