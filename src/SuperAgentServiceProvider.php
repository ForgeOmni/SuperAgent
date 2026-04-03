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
use SuperAgent\SkillDistillation\DistillationEngine;
use SuperAgent\SkillDistillation\DistillationManager;
use SuperAgent\SkillDistillation\DistillationStore;

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
