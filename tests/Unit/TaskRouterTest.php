<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Coordinator\AgentProviderConfig;
use SuperAgent\Coordinator\CollaborationPhase;
use SuperAgent\Coordinator\CollaborationPipeline;
use SuperAgent\Coordinator\TaskRouteResult;
use SuperAgent\Coordinator\TaskRouter;
use SuperAgent\CostPrediction\TaskProfile;
use SuperAgent\Swarm\AgentSpawnConfig;

class TaskRouterTest extends TestCase
{
    // ─── Default routing: task type → tier ──────────────────────

    public function testRouteCodeGenerationToTier2(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route(
            'Implement a new REST controller',
            TaskProfile::TYPE_CODE_GENERATION,
            TaskProfile::COMPLEXITY_MODERATE,
        );

        $this->assertSame(TaskRouter::TIER_BALANCE, $result->tier);
        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('claude-sonnet-4', $result->model);
        $this->assertSame(TaskProfile::TYPE_CODE_GENERATION, $result->taskType);
        $this->assertSame(TaskProfile::COMPLEXITY_MODERATE, $result->complexity);
    }

    public function testRouteResearchToTier3(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route(
            'Research the latest Redis API docs',
            TaskProfile::TYPE_RESEARCH,
            TaskProfile::COMPLEXITY_MODERATE,
        );

        $this->assertSame(TaskRouter::TIER_SPEED, $result->tier);
        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('claude-haiku-4', $result->model);
    }

    public function testRouteSynthesisToTier1(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route(
            'Synthesize all research findings into an architecture proposal',
            TaskProfile::TYPE_SYNTHESIS,
            TaskProfile::COMPLEXITY_MODERATE,
        );

        $this->assertSame(TaskRouter::TIER_POWER, $result->tier);
        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('claude-opus-4', $result->model);
    }

    public function testRouteCoordinationToTier1(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route(
            'Coordinate the multi-agent pipeline execution',
            TaskProfile::TYPE_COORDINATION,
            TaskProfile::COMPLEXITY_MODERATE,
        );

        $this->assertSame(TaskRouter::TIER_POWER, $result->tier);
        $this->assertSame('claude-opus-4', $result->model);
    }

    // ─── Complexity overrides ──────────────────────────────────

    public function testComplexityOverridePromotesCodeGenToTier1(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route(
            'Implement a distributed rate limiter with sliding window',
            TaskProfile::TYPE_CODE_GENERATION,
            TaskProfile::COMPLEXITY_VERY_COMPLEX,
        );

        $this->assertSame(TaskRouter::TIER_POWER, $result->tier);
        $this->assertSame('claude-opus-4', $result->model);
        $this->assertStringContainsString('promoted', $result->reason);
    }

    public function testComplexityOverridePromotesTestingToTier2(): void
    {
        $router = TaskRouter::withDefaults();

        // complex testing → Tier 2
        $resultComplex = $router->route(
            'Write integration tests for the payment gateway',
            TaskProfile::TYPE_TESTING,
            TaskProfile::COMPLEXITY_COMPLEX,
        );
        $this->assertSame(TaskRouter::TIER_BALANCE, $resultComplex->tier);
        $this->assertSame('claude-sonnet-4', $resultComplex->model);

        // very_complex testing → also Tier 2
        $resultVeryComplex = $router->route(
            'Write comprehensive E2E tests for the entire auth flow',
            TaskProfile::TYPE_TESTING,
            TaskProfile::COMPLEXITY_VERY_COMPLEX,
        );
        $this->assertSame(TaskRouter::TIER_BALANCE, $resultVeryComplex->tier);
    }

    public function testComplexityOverrideDemotesAnalysisToTier3(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route(
            'Quick analysis of this config file',
            TaskProfile::TYPE_ANALYSIS,
            TaskProfile::COMPLEXITY_SIMPLE,
        );

        $this->assertSame(TaskRouter::TIER_SPEED, $result->tier);
        $this->assertSame('claude-haiku-4', $result->model);
        $this->assertStringContainsString('demoted', $result->reason);
    }

    // ─── Explicit task type in route() ─────────────────────────

    public function testRouteWithExplicitTaskType(): void
    {
        $router = TaskRouter::withDefaults();

        // Pass explicit type and complexity — should NOT invoke analyzer
        $result = $router->route(
            'Some vague description',
            TaskProfile::TYPE_DEBUGGING,
            TaskProfile::COMPLEXITY_MODERATE,
        );

        $this->assertSame(TaskProfile::TYPE_DEBUGGING, $result->taskType);
        $this->assertSame(TaskProfile::COMPLEXITY_MODERATE, $result->complexity);
        $this->assertSame(TaskRouter::TIER_BALANCE, $result->tier);
    }

    // ─── routeToProviderConfig ─────────────────────────────────

    public function testRouteToProviderConfig(): void
    {
        $router = TaskRouter::withDefaults();
        $config = $router->routeToProviderConfig(
            'Research the latest API docs',
            TaskProfile::TYPE_RESEARCH,
        );

        $this->assertInstanceOf(AgentProviderConfig::class, $config);
        $this->assertSame('anthropic', $config->getProviderName());
    }

    // ─── TaskRouteResult ───────────────────────────────────────

    public function testTaskRouteResultToArray(): void
    {
        $result = new TaskRouteResult(
            provider: 'anthropic',
            model: 'claude-sonnet-4',
            tier: TaskRouter::TIER_BALANCE,
            taskType: TaskProfile::TYPE_CODE_GENERATION,
            complexity: TaskProfile::COMPLEXITY_MODERATE,
            reason: 'Test reason',
            estimatedCostMultiplier: 1.0,
        );

        $array = $result->toArray();

        $this->assertSame('anthropic', $array['provider']);
        $this->assertSame('claude-sonnet-4', $array['model']);
        $this->assertSame(2, $array['tier']);
        $this->assertSame('code_generation', $array['task_type']);
        $this->assertSame('moderate', $array['complexity']);
        $this->assertSame('Test reason', $array['reason']);
        $this->assertSame(1.0, $array['estimated_cost_multiplier']);
    }

    public function testTaskRouteResultToProviderConfig(): void
    {
        $result = new TaskRouteResult(
            provider: 'openai',
            model: 'gpt-4o',
            tier: TaskRouter::TIER_BALANCE,
            taskType: TaskProfile::TYPE_CODE_GENERATION,
            complexity: TaskProfile::COMPLEXITY_MODERATE,
            reason: 'Custom routing',
            estimatedCostMultiplier: 1.0,
        );

        $config = $result->toProviderConfig();

        $this->assertInstanceOf(AgentProviderConfig::class, $config);
        $this->assertSame('openai', $config->getProviderName());
        $this->assertSame('gpt-4o', $config->getConfig()['model']);
    }

    // ─── Custom tier models via fromConfig ─────────────────────

    public function testCustomTierModels(): void
    {
        $router = TaskRouter::fromConfig([
            'tier_models' => [
                TaskRouter::TIER_POWER => ['provider' => 'openai', 'model' => 'gpt-4o'],
                TaskRouter::TIER_BALANCE => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
                TaskRouter::TIER_SPEED => ['provider' => 'openai', 'model' => 'gpt-3.5-turbo'],
            ],
        ]);

        $result = $router->route(
            'Synthesize findings',
            TaskProfile::TYPE_SYNTHESIS,
            TaskProfile::COMPLEXITY_MODERATE,
        );

        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-4o', $result->model);
        $this->assertSame(TaskRouter::TIER_POWER, $result->tier);

        $resultSpeed = $router->route(
            'Quick chat',
            TaskProfile::TYPE_CHAT,
            TaskProfile::COMPLEXITY_SIMPLE,
        );

        $this->assertSame('openai', $resultSpeed->provider);
        $this->assertSame('gpt-3.5-turbo', $resultSpeed->model);
        $this->assertSame(TaskRouter::TIER_SPEED, $resultSpeed->tier);
    }

    // ─── selectModel clamping ──────────────────────────────────

    public function testSelectModelClampsTier(): void
    {
        $router = TaskRouter::withDefaults();

        // Tier 0 should clamp to Tier 1 (Power)
        $below = $router->selectModel(0);
        $this->assertSame('claude-opus-4', $below['model']);

        // Tier 4 should clamp to Tier 3 (Speed)
        $above = $router->selectModel(4);
        $this->assertSame('claude-haiku-4', $above['model']);

        // Tier -1 should also clamp to Tier 1
        $negative = $router->selectModel(-1);
        $this->assertSame('claude-opus-4', $negative['model']);
    }

    // ─── Default tier models ───────────────────────────────────

    public function testDefaultTierModels(): void
    {
        $defaults = TaskRouter::defaultTierModels();

        $this->assertCount(3, $defaults);

        $this->assertSame('anthropic', $defaults[TaskRouter::TIER_POWER]['provider']);
        $this->assertSame('claude-opus-4', $defaults[TaskRouter::TIER_POWER]['model']);

        $this->assertSame('anthropic', $defaults[TaskRouter::TIER_BALANCE]['provider']);
        $this->assertSame('claude-sonnet-4', $defaults[TaskRouter::TIER_BALANCE]['model']);

        $this->assertSame('anthropic', $defaults[TaskRouter::TIER_SPEED]['provider']);
        $this->assertSame('claude-haiku-4', $defaults[TaskRouter::TIER_SPEED]['model']);
    }

    // ─── Default task tier map ─────────────────────────────────

    public function testDefaultTaskTierMap(): void
    {
        $map = TaskRouter::defaultTaskTierMap();

        // Tier 1 (Power)
        $this->assertSame(TaskRouter::TIER_POWER, $map[TaskProfile::TYPE_SYNTHESIS]);
        $this->assertSame(TaskRouter::TIER_POWER, $map[TaskProfile::TYPE_COORDINATION]);

        // Tier 2 (Balance)
        $this->assertSame(TaskRouter::TIER_BALANCE, $map[TaskProfile::TYPE_CODE_GENERATION]);
        $this->assertSame(TaskRouter::TIER_BALANCE, $map[TaskProfile::TYPE_REFACTORING]);
        $this->assertSame(TaskRouter::TIER_BALANCE, $map[TaskProfile::TYPE_DEBUGGING]);
        $this->assertSame(TaskRouter::TIER_BALANCE, $map[TaskProfile::TYPE_ANALYSIS]);
        $this->assertSame(TaskRouter::TIER_BALANCE, $map[TaskProfile::TYPE_MULTI_FILE]);

        // Tier 3 (Speed)
        $this->assertSame(TaskRouter::TIER_SPEED, $map[TaskProfile::TYPE_TESTING]);
        $this->assertSame(TaskRouter::TIER_SPEED, $map[TaskProfile::TYPE_RESEARCH]);
        $this->assertSame(TaskRouter::TIER_SPEED, $map[TaskProfile::TYPE_CHAT]);
    }

    // ─── Cost multipliers ──────────────────────────────────────

    public function testCostMultiplierByTier(): void
    {
        $router = TaskRouter::withDefaults();

        $tier1 = $router->route('x', TaskProfile::TYPE_SYNTHESIS, TaskProfile::COMPLEXITY_MODERATE);
        $this->assertSame(5.0, $tier1->estimatedCostMultiplier);

        $tier2 = $router->route('x', TaskProfile::TYPE_CODE_GENERATION, TaskProfile::COMPLEXITY_MODERATE);
        $this->assertSame(1.0, $tier2->estimatedCostMultiplier);

        $tier3 = $router->route('x', TaskProfile::TYPE_CHAT, TaskProfile::COMPLEXITY_SIMPLE);
        $this->assertSame(0.27, $tier3->estimatedCostMultiplier);
    }

    // ─── Additional complexity override coverage ───────────────

    public function testComplexityOverridePromotesRefactoringToTier1(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route(
            'Major architectural refactor of the entire codebase',
            TaskProfile::TYPE_REFACTORING,
            TaskProfile::COMPLEXITY_VERY_COMPLEX,
        );

        $this->assertSame(TaskRouter::TIER_POWER, $result->tier);
    }

    public function testComplexityOverridePromotesResearchToTier2(): void
    {
        $router = TaskRouter::withDefaults();

        $complex = $router->route('x', TaskProfile::TYPE_RESEARCH, TaskProfile::COMPLEXITY_COMPLEX);
        $this->assertSame(TaskRouter::TIER_BALANCE, $complex->tier);

        $veryComplex = $router->route('x', TaskProfile::TYPE_RESEARCH, TaskProfile::COMPLEXITY_VERY_COMPLEX);
        $this->assertSame(TaskRouter::TIER_BALANCE, $veryComplex->tier);
    }

    public function testComplexityOverridePromotesChatToTier2(): void
    {
        $router = TaskRouter::withDefaults();

        $complex = $router->route('x', TaskProfile::TYPE_CHAT, TaskProfile::COMPLEXITY_COMPLEX);
        $this->assertSame(TaskRouter::TIER_BALANCE, $complex->tier);

        $veryComplex = $router->route('x', TaskProfile::TYPE_CHAT, TaskProfile::COMPLEXITY_VERY_COMPLEX);
        $this->assertSame(TaskRouter::TIER_BALANCE, $veryComplex->tier);
    }

    // ─── getTierForTask directly ───────────────────────────────

    public function testGetTierForTaskUnknownTypeDefaultsToBalance(): void
    {
        $router = TaskRouter::withDefaults();
        $tier = $router->getTierForTask('unknown_type', TaskProfile::COMPLEXITY_MODERATE);

        $this->assertSame(TaskRouter::TIER_BALANCE, $tier);
    }

    // ─── Reason string ─────────────────────────────────────────

    public function testRouteReasonContainsTaskType(): void
    {
        $router = TaskRouter::withDefaults();
        $result = $router->route('x', TaskProfile::TYPE_DEBUGGING, TaskProfile::COMPLEXITY_MODERATE);

        $this->assertStringContainsString('debugging', $result->reason);
        $this->assertStringContainsString('Tier 2', $result->reason);
        $this->assertStringContainsString('Balance', $result->reason);
    }

    // ─── Integration: CollaborationPhase with auto-routing ─────

    public function testPhaseWithAutoRouting(): void
    {
        $phase = new CollaborationPhase('research');
        $this->assertFalse($phase->isAutoRoutingEnabled());

        $phase->withAutoRouting();
        $this->assertTrue($phase->isAutoRoutingEnabled());
        $this->assertNotNull($phase->getTaskRouter());
    }

    public function testPhaseAutoRoutingExplicitOverrideWins(): void
    {
        $phase = new CollaborationPhase('mixed');
        $phase->withAutoRouting();

        $explicitConfig = AgentProviderConfig::crossProvider('openai', [
            'model' => 'gpt-4o',
        ]);
        $phase->withAgentProvider('special-agent', $explicitConfig);

        $phase->addAgent(new AgentSpawnConfig(name: 'special-agent', prompt: 'Research docs'));
        $phase->addAgent(new AgentSpawnConfig(name: 'auto-agent', prompt: 'Research docs'));

        // Explicit override wins for special-agent
        $specialConfig = $phase->getProviderConfigFor('special-agent');
        $this->assertSame('openai', $specialConfig->getProviderName());
        $this->assertSame('gpt-4o', $specialConfig->getConfig()['model']);

        // Auto-routed agent gets a config from the router
        $autoConfig = $phase->getProviderConfigFor('auto-agent');
        $this->assertInstanceOf(AgentProviderConfig::class, $autoConfig);
        $this->assertSame('anthropic', $autoConfig->getProviderName());
    }

    public function testPipelineAutoRoutingPropagates(): void
    {
        $pipeline = CollaborationPipeline::create()
            ->withAutoRouting();

        $this->assertTrue($pipeline->isAutoRoutingEnabled());
        $this->assertNotNull($pipeline->getTaskRouter());

        // Phases created via the builder inherit auto-routing
        $pipeline->phase('analysis', function (CollaborationPhase $phase) {
            $phase->addAgent(new AgentSpawnConfig(name: 'analyzer', prompt: 'Analyze code'));
        });

        $phase = $pipeline->getPhase('analysis');
        $this->assertNotNull($phase);
        $this->assertTrue($phase->isAutoRoutingEnabled());
        $this->assertNotNull($phase->getTaskRouter());
    }

    // ─── fromConfig with custom complexity_overrides ───────────

    public function testFromConfigWithCustomOverrides(): void
    {
        $router = TaskRouter::fromConfig([
            'complexity_overrides' => [
                TaskProfile::TYPE_CHAT => [
                    TaskProfile::COMPLEXITY_SIMPLE => TaskRouter::TIER_SPEED,
                    TaskProfile::COMPLEXITY_VERY_COMPLEX => TaskRouter::TIER_POWER,
                ],
            ],
        ]);

        $tier = $router->getTierForTask(TaskProfile::TYPE_CHAT, TaskProfile::COMPLEXITY_VERY_COMPLEX);
        $this->assertSame(TaskRouter::TIER_POWER, $tier);

        $tierSimple = $router->getTierForTask(TaskProfile::TYPE_CHAT, TaskProfile::COMPLEXITY_SIMPLE);
        $this->assertSame(TaskRouter::TIER_SPEED, $tierSimple);
    }

    // ─── Tier constants ────────────────────────────────────────

    public function testTierConstants(): void
    {
        $this->assertSame(1, TaskRouter::TIER_POWER);
        $this->assertSame(2, TaskRouter::TIER_BALANCE);
        $this->assertSame(3, TaskRouter::TIER_SPEED);
    }
}
