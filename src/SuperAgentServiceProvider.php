<?php

namespace SuperAgent;

use Illuminate\Support\ServiceProvider;
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\CostAutopilot\BudgetConfig;
use SuperAgent\CostAutopilot\BudgetTracker;
use SuperAgent\CostAutopilot\CostAutopilot;
use SuperAgent\AdaptiveFeedback\AdaptiveFeedbackEngine;
use SuperAgent\AdaptiveFeedback\CorrectionCollector;
use SuperAgent\AdaptiveFeedback\CorrectionStore;
use SuperAgent\AdaptiveFeedback\FeedbackManager;
use SuperAgent\Pipeline\PipelineConfig;
use SuperAgent\Pipeline\PipelineEngine;
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\CheckpointStore;
use SuperAgent\KnowledgeGraph\GraphCollector;
use SuperAgent\KnowledgeGraph\KnowledgeGraph;
use SuperAgent\KnowledgeGraph\KnowledgeGraphManager;
use SuperAgent\SmartContext\SmartContextManager;
use SuperAgent\Providers\ModelResolver;
use SuperAgent\SkillDistillation\DistillationEngine;
use SuperAgent\SkillDistillation\DistillationManager;
use SuperAgent\SkillDistillation\DistillationStore;
use SuperAgent\Replay\ReplayRecorder;
use SuperAgent\Replay\ReplayStore;
use SuperAgent\Fork\ForkExecutor;
use SuperAgent\Fork\ForkManager;
use SuperAgent\Debate\DebateOrchestrator;
use SuperAgent\CostPrediction\CostHistoryStore;
use SuperAgent\CostPrediction\CostPredictor;
use SuperAgent\Guardrails\NaturalLanguage\NLGuardrailCompiler;
use SuperAgent\Pipeline\SelfHealing\SelfHealingStrategy;
use SuperAgent\Guardrails\PromptInjectionDetector;
use SuperAgent\Providers\CredentialPool;
use SuperAgent\Optimization\QueryComplexityRouter;
use SuperAgent\Optimization\ContextCompression\ContextCompressor;
use SuperAgent\Memory\MemoryProviderManager;
use SuperAgent\Memory\BuiltinMemoryProvider;
use SuperAgent\Memory\Contracts\MemoryProviderInterface;
use SuperAgent\Middleware\MiddlewarePipeline;
use SuperAgent\Tools\ToolResultCache;

class SuperAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/superagent.php', 'superagent');

        $this->app->bind(Agent::class, function ($app) {
            return new Agent();
        });

        $this->app->alias(Agent::class, 'superagent');

        // Register GuardrailsEngine singleton when enabled
        $this->app->singleton(GuardrailsEngine::class, function ($app) {
            $config = $app['config']->get('superagent.guardrails', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $files = $config['files'] ?? [];
            if (empty($files)) {
                return null;
            }

            $guardrailsConfig = GuardrailsConfig::fromYamlFiles($files);
            $errors = $guardrailsConfig->validate();

            if (!empty($errors)) {
                logger()->warning('Guardrails config validation errors', ['errors' => $errors]);
            }

            return new GuardrailsEngine($guardrailsConfig);
        });

        // Register CostAutopilot singleton when enabled
        $this->app->singleton(CostAutopilot::class, function ($app) {
            $config = $app['config']->get('superagent.cost_autopilot', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $budgetConfig = BudgetConfig::fromArray($config);
            $errors = $budgetConfig->validate();

            if (!empty($errors)) {
                logger()->warning('CostAutopilot config validation errors', ['errors' => $errors]);
            }

            $autopilot = new CostAutopilot($budgetConfig);

            // Wire up persistent budget tracker
            $storagePath = $config['storage_path']
                ?? storage_path('superagent/budget_tracker.json');
            $autopilot->setBudgetTracker(new BudgetTracker($storagePath));

            // Auto-detect model tiers from provider if not explicitly configured
            if (empty($config['tiers'])) {
                $provider = $app['config']->get('superagent.default_provider', 'anthropic');
                $tiers = match ($provider) {
                    'anthropic' => \SuperAgent\CostAutopilot\ModelTier::anthropicTiers(),
                    'openai' => \SuperAgent\CostAutopilot\ModelTier::openaiTiers(),
                    default => [],
                };
                if (!empty($tiers)) {
                    $budgetConfig->setTiers($tiers);
                }
            }

            return $autopilot;
        });

        // Register AdaptiveFeedback singletons when enabled
        $this->app->singleton(CorrectionStore::class, function ($app) {
            $config = $app['config']->get('superagent.adaptive_feedback', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $storagePath = $config['storage_path']
                ?? storage_path('superagent/corrections.json');

            return new CorrectionStore($storagePath);
        });

        $this->app->singleton(FeedbackManager::class, function ($app) {
            $store = $app->make(CorrectionStore::class);
            if ($store === null) {
                return null;
            }

            $config = $app['config']->get('superagent.adaptive_feedback', []);

            $engine = new AdaptiveFeedbackEngine(
                store: $store,
                promotionThreshold: (int) ($config['promotion_threshold'] ?? 3),
                autoPromote: (bool) ($config['auto_promote'] ?? true),
            );

            $collector = new CorrectionCollector($store);

            return new FeedbackManager($store, $engine, $collector);
        });

        // Register SmartContextManager singleton when enabled
        $this->app->singleton(SmartContextManager::class, function ($app) {
            $config = $app['config']->get('superagent.smart_context', []);

            if (empty($config['enabled'])) {
                return null;
            }

            return new SmartContextManager(
                totalBudgetTokens: (int) ($config['total_budget_tokens'] ?? 100_000),
                minThinkingBudget: (int) ($config['min_thinking_budget'] ?? 5_000),
                maxThinkingBudget: (int) ($config['max_thinking_budget'] ?? 128_000),
                configEnabled: true,
            );
        });

        // Register KnowledgeGraphManager singleton when enabled
        $this->app->singleton(KnowledgeGraphManager::class, function ($app) {
            $config = $app['config']->get('superagent.knowledge_graph', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $storagePath = $config['storage_path']
                ?? storage_path('superagent/knowledge_graph.json');

            $graph = new KnowledgeGraph($storagePath);
            $collector = new GraphCollector($graph);

            return new KnowledgeGraphManager($graph, $collector);
        });

        // Register CheckpointManager singleton when enabled
        $this->app->singleton(CheckpointManager::class, function ($app) {
            $config = $app['config']->get('superagent.checkpoint', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $storagePath = $config['storage_path']
                ?? storage_path('superagent/checkpoints');

            return new CheckpointManager(
                store: new CheckpointStore($storagePath),
                interval: (int) ($config['interval'] ?? 5),
                maxPerSession: (int) ($config['max_per_session'] ?? 5),
                configEnabled: true,
            );
        });

        // Register DistillationManager singleton when enabled
        $this->app->singleton(DistillationManager::class, function ($app) {
            $config = $app['config']->get('superagent.skill_distillation', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $storagePath = $config['storage_path']
                ?? storage_path('superagent/distilled_skills.json');

            $store = new DistillationStore($storagePath);
            $engine = new DistillationEngine(
                store: $store,
                minSteps: (int) ($config['min_steps'] ?? 3),
                minCostUsd: (float) ($config['min_cost_usd'] ?? 0.01),
            );

            return new DistillationManager($store, $engine);
        });

        // Register ReplayStore singleton when enabled
        $this->app->singleton(ReplayStore::class, function ($app) {
            $config = $app['config']->get('superagent.replay', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $storagePath = $config['storage_path']
                ?? storage_path('superagent/replays');

            return new ReplayStore($storagePath);
        });

        // Register ForkManager singleton when enabled
        $this->app->singleton(ForkManager::class, function ($app) {
            $config = $app['config']->get('superagent.fork', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $executor = new ForkExecutor(
                defaultTimeout: (int) ($config['default_timeout'] ?? 300),
            );

            return new ForkManager($executor);
        });

        // Register DebateOrchestrator singleton when enabled
        $this->app->singleton(DebateOrchestrator::class, function ($app) {
            $config = $app['config']->get('superagent.debate', []);

            if (empty($config['enabled'])) {
                return null;
            }

            // The agent runner callback must be set externally
            return new DebateOrchestrator(
                agentRunner: fn() => ['content' => '', 'cost' => 0.0, 'turns' => 0],
                defaultConfig: $config,
            );
        });

        // Register CostPredictor singleton when enabled
        $this->app->singleton(CostPredictor::class, function ($app) {
            $config = $app['config']->get('superagent.cost_prediction', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $storagePath = $config['storage_path']
                ?? storage_path('superagent/cost_history');

            return new CostPredictor(
                historyStore: new CostHistoryStore($storagePath),
                config: $config,
            );
        });

        // Register NLGuardrailCompiler singleton when enabled
        $this->app->singleton(NLGuardrailCompiler::class, function ($app) {
            $config = $app['config']->get('superagent.nl_guardrails', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $compiler = new NLGuardrailCompiler();

            // Auto-compile configured rules and merge into GuardrailsEngine
            $rules = $config['rules'] ?? [];
            if (!empty($rules)) {
                $compiled = $compiler->compileAll($rules);
                $engine = $app->make(GuardrailsEngine::class);
                if ($engine !== null) {
                    // The compiled rules will be available for the engine
                    logger()->info('NL Guardrails compiled', [
                        'total' => $compiled->totalRules,
                        'high_confidence' => $compiled->highConfidenceCount,
                        'needs_review' => $compiled->needsReviewCount,
                    ]);
                }
            }

            return $compiler;
        });

        // Register PromptInjectionDetector singleton
        $this->app->singleton(PromptInjectionDetector::class, function ($app) {
            return new PromptInjectionDetector();
        });

        // Register CredentialPool singleton and integrate with ProviderRegistry
        $this->app->singleton(CredentialPool::class, function ($app) {
            $config = $app['config']->get('superagent.credential_pool', []);
            $pool = CredentialPool::fromConfig($config);

            // Integrate with ProviderRegistry for automatic key rotation
            if (!empty($config)) {
                \SuperAgent\Providers\ProviderRegistry::setCredentialPool($pool);
            }

            return $pool;
        });

        // Register QueryComplexityRouter singleton when enabled
        $this->app->singleton(QueryComplexityRouter::class, function ($app) {
            $config = $app['config']->get('superagent.optimization.query_complexity_routing', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $currentModel = $app['config']->get('superagent.model', 'claude-sonnet-4-6');
            return QueryComplexityRouter::fromConfig($currentModel);
        });

        // Register ContextCompressor singleton when enabled
        $this->app->singleton(ContextCompressor::class, function ($app) {
            $config = $app['config']->get('superagent.optimization.context_compression', []);

            if (empty($config['enabled'])) {
                return null;
            }

            return ContextCompressor::fromConfig();
        });

        // Register MemoryProviderManager singleton
        $this->app->singleton(MemoryProviderManager::class, function ($app) {
            $builtinProvider = new BuiltinMemoryProvider();
            return new MemoryProviderManager($builtinProvider);
        });

        // Register MiddlewarePipeline singleton
        $this->app->singleton(MiddlewarePipeline::class, function ($app) {
            return new MiddlewarePipeline();
        });

        // Register ToolResultCache singleton
        $this->app->singleton(ToolResultCache::class, function ($app) {
            $config = $app['config']->get('superagent.optimization.tool_cache', []);

            return new ToolResultCache(
                defaultTtlSeconds: (int) ($config['default_ttl'] ?? 300),
                maxEntries: (int) ($config['max_entries'] ?? 1000),
            );
        });

        // Register SelfHealingStrategy singleton when enabled
        $this->app->singleton(SelfHealingStrategy::class, function ($app) {
            $config = $app['config']->get('superagent.self_healing', []);

            if (empty($config['enabled'])) {
                return null;
            }

            return new SelfHealingStrategy(config: $config);
        });

        // Register PipelineEngine singleton when enabled
        $this->app->singleton(PipelineEngine::class, function ($app) {
            $config = $app['config']->get('superagent.pipelines', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $files = $config['files'] ?? [];
            if (empty($files)) {
                return null;
            }

            $pipelineConfig = PipelineConfig::fromYamlFiles($files);
            $errors = $pipelineConfig->validate();

            if (!empty($errors)) {
                logger()->warning('Pipeline config validation errors', ['errors' => $errors]);
            }

            return new PipelineEngine($pipelineConfig);
        });
    }

    public function boot(): void
    {
        // Register custom model aliases from config
        $modelAliases = $this->app['config']->get('superagent.model_aliases', []);
        if (! empty($modelAliases)) {
            ModelResolver::registerAliases($modelAliases);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/superagent.php' => config_path('superagent.php'),
            ], 'superagent-config');

            $this->commands([
                \SuperAgent\Console\Commands\FeedbackCommand::class,
                \SuperAgent\Console\Commands\DistillCommand::class,
                \SuperAgent\Console\Commands\CheckpointCommand::class,
            ]);
        }

        // Register Bridge routes when bridge_mode is enabled
        if (\SuperAgent\Config\ExperimentalFeatures::enabled('bridge_mode')) {
            $this->app->register(\SuperAgent\Bridge\BridgeServiceProvider::class);
        }
    }
}
