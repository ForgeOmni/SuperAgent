<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use SuperAgent\CostPrediction\TaskAnalyzer;
use SuperAgent\CostPrediction\TaskProfile;

/**
 * Routes tasks to optimal provider/model combinations based on task type and complexity.
 *
 * Three model tiers:
 *   - Tier 1 (Power):   Complex reasoning, synthesis, architecture (Opus, GPT-4o)
 *   - Tier 2 (Balance): Code writing, debugging, analysis (Sonnet, GPT-4o)
 *   - Tier 3 (Speed):   Research, extraction, testing, chat (Haiku, GPT-4o-mini)
 *
 * Auto-routing selects the cheapest tier that can handle the task well,
 * with complexity overrides promoting tasks to higher tiers when needed.
 *
 * Usage:
 *   $router = TaskRouter::withDefaults();
 *   $route = $router->route('Research the latest API docs for Redis');
 *   // → tier 3 (Haiku), detected as "research" task
 *
 *   $route = $router->route('Implement a distributed rate limiter with sliding window');
 *   // → tier 2 (Sonnet), detected as "code_generation" + complex
 */
class TaskRouter
{
    public const TIER_POWER = 1;
    public const TIER_BALANCE = 2;
    public const TIER_SPEED = 3;

    /**
     * @param array<int, array{provider: string, model: string}> $tierModels
     * @param array<string, int> $taskTierMap
     * @param array<string, array<string, int>> $complexityOverrides task_type => {complexity => tier}
     */
    public function __construct(
        private array $tierModels = [],
        private array $taskTierMap = [],
        private array $complexityOverrides = [],
        private ?TaskAnalyzer $analyzer = null,
    ) {
        if (empty($this->tierModels)) {
            $this->tierModels = self::defaultTierModels();
        }
        if (empty($this->taskTierMap)) {
            $this->taskTierMap = self::defaultTaskTierMap();
        }
        if (empty($this->complexityOverrides)) {
            $this->complexityOverrides = self::defaultComplexityOverrides();
        }
        $this->analyzer ??= new TaskAnalyzer();
    }

    /**
     * Factory with sensible defaults.
     */
    public static function withDefaults(): self
    {
        return new self();
    }

    /**
     * Factory from config array.
     */
    public static function fromConfig(array $config = []): self
    {
        return new self(
            tierModels: $config['tier_models'] ?? [],
            taskTierMap: $config['task_tier_map'] ?? [],
            complexityOverrides: $config['complexity_overrides'] ?? [],
        );
    }

    /**
     * Route a task description to the optimal provider/model.
     */
    public function route(
        string $taskDescription,
        ?string $taskType = null,
        ?string $complexity = null,
    ): TaskRouteResult {
        // Analyze the task if type/complexity not provided
        if ($taskType === null || $complexity === null) {
            $profile = $this->analyzer->analyze($taskDescription);
            $taskType ??= $profile->taskType;
            $complexity ??= $profile->complexity;
        }

        $tier = $this->getTierForTask($taskType, $complexity);
        $model = $this->selectModel($tier);
        $reason = $this->buildReason($taskType, $complexity, $tier);

        $costMultiplier = match ($tier) {
            self::TIER_POWER => 5.0,
            self::TIER_BALANCE => 1.0,
            self::TIER_SPEED => 0.27,
            default => 1.0,
        };

        return new TaskRouteResult(
            provider: $model['provider'],
            model: $model['model'],
            tier: $tier,
            taskType: $taskType,
            complexity: $complexity,
            reason: $reason,
            estimatedCostMultiplier: $costMultiplier,
        );
    }

    /**
     * Route and return a ready-to-use AgentProviderConfig.
     */
    public function routeToProviderConfig(
        string $taskDescription,
        ?string $taskType = null,
    ): AgentProviderConfig {
        return $this->route($taskDescription, $taskType)->toProviderConfig();
    }

    /**
     * Determine the model tier for a task type + complexity combination.
     */
    public function getTierForTask(string $taskType, string $complexity): int
    {
        $baseTier = $this->taskTierMap[$taskType] ?? self::TIER_BALANCE;

        // Check complexity overrides
        $overrides = $this->complexityOverrides[$taskType] ?? [];
        if (isset($overrides[$complexity])) {
            return max(self::TIER_POWER, min(self::TIER_SPEED, $overrides[$complexity]));
        }

        return $baseTier;
    }

    /**
     * Get the model configuration for a given tier.
     *
     * @return array{provider: string, model: string}
     */
    public function selectModel(int $tier): array
    {
        $tier = max(self::TIER_POWER, min(self::TIER_SPEED, $tier));
        return $this->tierModels[$tier] ?? $this->tierModels[self::TIER_BALANCE] ?? [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4',
        ];
    }

    // ── Accessors ───────────────────────────────────────────────

    /** @return array<int, array{provider: string, model: string}> */
    public function getTierModels(): array
    {
        return $this->tierModels;
    }

    /** @return array<string, int> */
    public function getTaskTierMap(): array
    {
        return $this->taskTierMap;
    }

    // ── Defaults ────────────────────────────────────────────────

    /** @return array<int, array{provider: string, model: string}> */
    public static function defaultTierModels(): array
    {
        return [
            self::TIER_POWER => ['provider' => 'anthropic', 'model' => 'claude-opus-4'],
            self::TIER_BALANCE => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4'],
            self::TIER_SPEED => ['provider' => 'anthropic', 'model' => 'claude-haiku-4'],
        ];
    }

    /** @return array<string, int> */
    public static function defaultTaskTierMap(): array
    {
        return [
            // Tier 1: Power
            TaskProfile::TYPE_SYNTHESIS => self::TIER_POWER,
            TaskProfile::TYPE_COORDINATION => self::TIER_POWER,

            // Tier 2: Balance
            TaskProfile::TYPE_CODE_GENERATION => self::TIER_BALANCE,
            TaskProfile::TYPE_REFACTORING => self::TIER_BALANCE,
            TaskProfile::TYPE_DEBUGGING => self::TIER_BALANCE,
            TaskProfile::TYPE_ANALYSIS => self::TIER_BALANCE,
            TaskProfile::TYPE_MULTI_FILE => self::TIER_BALANCE,

            // Tier 3: Speed
            TaskProfile::TYPE_TESTING => self::TIER_SPEED,
            TaskProfile::TYPE_RESEARCH => self::TIER_SPEED,
            TaskProfile::TYPE_CHAT => self::TIER_SPEED,
        ];
    }

    /** @return array<string, array<string, int>> */
    public static function defaultComplexityOverrides(): array
    {
        return [
            // very_complex tasks get promoted to higher tier
            TaskProfile::TYPE_CODE_GENERATION => [
                TaskProfile::COMPLEXITY_VERY_COMPLEX => self::TIER_POWER,
            ],
            TaskProfile::TYPE_REFACTORING => [
                TaskProfile::COMPLEXITY_VERY_COMPLEX => self::TIER_POWER,
            ],
            TaskProfile::TYPE_MULTI_FILE => [
                TaskProfile::COMPLEXITY_VERY_COMPLEX => self::TIER_POWER,
            ],

            // Complex testing/research promoted to balance tier
            TaskProfile::TYPE_TESTING => [
                TaskProfile::COMPLEXITY_COMPLEX => self::TIER_BALANCE,
                TaskProfile::COMPLEXITY_VERY_COMPLEX => self::TIER_BALANCE,
            ],
            TaskProfile::TYPE_RESEARCH => [
                TaskProfile::COMPLEXITY_COMPLEX => self::TIER_BALANCE,
                TaskProfile::COMPLEXITY_VERY_COMPLEX => self::TIER_BALANCE,
            ],

            // Simple analysis demoted to speed tier
            TaskProfile::TYPE_ANALYSIS => [
                TaskProfile::COMPLEXITY_SIMPLE => self::TIER_SPEED,
            ],

            // Complex chat promoted to balance tier
            TaskProfile::TYPE_CHAT => [
                TaskProfile::COMPLEXITY_COMPLEX => self::TIER_BALANCE,
                TaskProfile::COMPLEXITY_VERY_COMPLEX => self::TIER_BALANCE,
            ],
        ];
    }

    private function buildReason(string $taskType, string $complexity, int $tier): string
    {
        $tierName = match ($tier) {
            self::TIER_POWER => 'Power',
            self::TIER_BALANCE => 'Balance',
            self::TIER_SPEED => 'Speed',
            default => 'Unknown',
        };

        $baseTier = $this->taskTierMap[$taskType] ?? self::TIER_BALANCE;
        $promoted = $tier < $baseTier;
        $demoted = $tier > $baseTier;

        $reason = "Task type '{$taskType}' → Tier {$tier} ({$tierName})";
        if ($promoted) {
            $reason .= " [promoted from Tier {$baseTier}: {$complexity} complexity]";
        } elseif ($demoted) {
            $reason .= " [demoted from Tier {$baseTier}: {$complexity} complexity]";
        }

        return $reason;
    }
}
