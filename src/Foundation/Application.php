<?php

declare(strict_types=1);

namespace SuperAgent\Foundation;

use SuperAgent\Config\ConfigRepository;

/**
 * Lightweight application container for standalone (non-Laravel) usage.
 *
 * Provides a minimal service container with singleton/bind/make support,
 * mirroring the subset of Laravel's Application used by SuperAgent.
 *
 * In a Laravel app, the framework's app() helper returns the full
 * Laravel Application instead, and this class is never instantiated.
 */
class Application
{
    private static ?self $instance = null;

    private string $basePath;
    private string $storagePath;
    private string $environment = 'production';

    /** @var array<string, callable> Factory closures */
    private array $bindings = [];

    /** @var array<string, mixed> Resolved singleton instances */
    private array $singletons = [];

    /** @var array<string, string> Aliases: alias → concrete */
    private array $aliases = [];

    /** @var array<string, bool> Keys that should be resolved as singletons */
    private array $singletonKeys = [];

    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath ?: getcwd();
        $this->storagePath = $this->resolveStoragePath();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    // --- Container methods ---

    /** Register a binding (factory closure). */
    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    /** Register a singleton binding. */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
        $this->singletonKeys[$abstract] = true;
    }

    /** Register an alias for a binding. */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /** Check if a binding exists. */
    public function bound(string $abstract): bool
    {
        $abstract = $this->resolveAlias($abstract);

        return isset($this->bindings[$abstract])
            || isset($this->singletons[$abstract]);
    }

    /**
     * Resolve a binding from the container.
     *
     * @param  string|null  $abstract  Class or alias to resolve. Null returns the app itself.
     */
    public function make(?string $abstract = null): mixed
    {
        if ($abstract === null) {
            return $this;
        }

        $abstract = $this->resolveAlias($abstract);

        // Return existing singleton
        if (isset($this->singletons[$abstract])) {
            return $this->singletons[$abstract];
        }

        // Resolve from binding
        if (isset($this->bindings[$abstract])) {
            $instance = ($this->bindings[$abstract])($this);

            if (isset($this->singletonKeys[$abstract])) {
                $this->singletons[$abstract] = $instance;
            }

            return $instance;
        }

        // Special cases
        if ($abstract === 'config') {
            return ConfigRepository::getInstance();
        }

        return null;
    }

    /**
     * Array-access syntax: $app['config'] etc.
     */
    public function __get(string $name): mixed
    {
        return $this->make($name);
    }

    // --- Path methods ---

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    public function storagePath(string $path = ''): string
    {
        return $this->storagePath . ($path ? '/' . ltrim($path, '/\\') : '');
    }

    public function setBasePath(string $basePath): void
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function setEnvironment(string $env): void
    {
        $this->environment = $env;
    }

    // --- Service registration (mirrors SuperAgentServiceProvider) ---

    /**
     * Register all SuperAgent core singletons.
     * This replicates the logic from SuperAgentServiceProvider::register().
     */
    public function registerCoreServices(): void
    {
        $config = ConfigRepository::getInstance();

        // Agent
        $this->bind(\SuperAgent\Agent::class, function () {
            return new \SuperAgent\Agent();
        });
        $this->alias(\SuperAgent\Agent::class, 'superagent');

        // GuardrailsEngine
        $this->singleton(\SuperAgent\Guardrails\GuardrailsEngine::class, function () use ($config) {
            $cfg = $config->get('superagent.guardrails', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $files = $cfg['files'] ?? [];
            if (empty($files)) {
                return null;
            }
            $guardrailsConfig = \SuperAgent\Guardrails\GuardrailsConfig::fromYamlFiles($files);
            return new \SuperAgent\Guardrails\GuardrailsEngine($guardrailsConfig);
        });

        // CostAutopilot
        $this->singleton(\SuperAgent\CostAutopilot\CostAutopilot::class, function () use ($config) {
            $cfg = $config->get('superagent.cost_autopilot', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $budgetConfig = \SuperAgent\CostAutopilot\BudgetConfig::fromArray($cfg);
            $autopilot = new \SuperAgent\CostAutopilot\CostAutopilot($budgetConfig);
            $storagePath = $cfg['storage_path'] ?? $this->storagePath('superagent/budget_tracker.json');
            $autopilot->setBudgetTracker(new \SuperAgent\CostAutopilot\BudgetTracker($storagePath));
            return $autopilot;
        });

        // CorrectionStore + FeedbackManager
        $this->singleton(\SuperAgent\AdaptiveFeedback\CorrectionStore::class, function () use ($config) {
            $cfg = $config->get('superagent.adaptive_feedback', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $storagePath = $cfg['storage_path'] ?? $this->storagePath('superagent/corrections.json');
            return new \SuperAgent\AdaptiveFeedback\CorrectionStore($storagePath);
        });

        $this->singleton(\SuperAgent\AdaptiveFeedback\FeedbackManager::class, function () {
            $store = $this->make(\SuperAgent\AdaptiveFeedback\CorrectionStore::class);
            if ($store === null) {
                return null;
            }
            $config = ConfigRepository::getInstance()->get('superagent.adaptive_feedback', []);
            $engine = new \SuperAgent\AdaptiveFeedback\AdaptiveFeedbackEngine(
                store: $store,
                promotionThreshold: (int) ($config['promotion_threshold'] ?? 3),
                autoPromote: (bool) ($config['auto_promote'] ?? true),
            );
            $collector = new \SuperAgent\AdaptiveFeedback\CorrectionCollector($store);
            return new \SuperAgent\AdaptiveFeedback\FeedbackManager($store, $engine, $collector);
        });

        // SmartContextManager
        $this->singleton(\SuperAgent\SmartContext\SmartContextManager::class, function () use ($config) {
            $cfg = $config->get('superagent.smart_context', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            return new \SuperAgent\SmartContext\SmartContextManager(
                totalBudgetTokens: (int) ($cfg['total_budget_tokens'] ?? 100_000),
                minThinkingBudget: (int) ($cfg['min_thinking_budget'] ?? 5_000),
                maxThinkingBudget: (int) ($cfg['max_thinking_budget'] ?? 128_000),
                configEnabled: true,
            );
        });

        // KnowledgeGraphManager
        $this->singleton(\SuperAgent\KnowledgeGraph\KnowledgeGraphManager::class, function () use ($config) {
            $cfg = $config->get('superagent.knowledge_graph', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $storagePath = $cfg['storage_path'] ?? $this->storagePath('superagent/knowledge_graph.json');
            $graph = new \SuperAgent\KnowledgeGraph\KnowledgeGraph($storagePath);
            $collector = new \SuperAgent\KnowledgeGraph\GraphCollector($graph);
            return new \SuperAgent\KnowledgeGraph\KnowledgeGraphManager($graph, $collector);
        });

        // CheckpointManager
        $this->singleton(\SuperAgent\Checkpoint\CheckpointManager::class, function () use ($config) {
            $cfg = $config->get('superagent.checkpoint', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $storagePath = $cfg['storage_path'] ?? $this->storagePath('superagent/checkpoints');
            return new \SuperAgent\Checkpoint\CheckpointManager(
                store: new \SuperAgent\Checkpoint\CheckpointStore($storagePath),
                interval: (int) ($cfg['interval'] ?? 5),
                maxPerSession: (int) ($cfg['max_per_session'] ?? 5),
                configEnabled: true,
            );
        });

        // DistillationManager
        $this->singleton(\SuperAgent\SkillDistillation\DistillationManager::class, function () use ($config) {
            $cfg = $config->get('superagent.skill_distillation', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $storagePath = $cfg['storage_path'] ?? $this->storagePath('superagent/distilled_skills.json');
            $store = new \SuperAgent\SkillDistillation\DistillationStore($storagePath);
            $engine = new \SuperAgent\SkillDistillation\DistillationEngine(
                store: $store,
                minSteps: (int) ($cfg['min_steps'] ?? 3),
                minCostUsd: (float) ($cfg['min_cost_usd'] ?? 0.01),
            );
            return new \SuperAgent\SkillDistillation\DistillationManager($store, $engine);
        });

        // ReplayStore
        $this->singleton(\SuperAgent\Replay\ReplayStore::class, function () use ($config) {
            $cfg = $config->get('superagent.replay', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $storagePath = $cfg['storage_path'] ?? $this->storagePath('superagent/replays');
            return new \SuperAgent\Replay\ReplayStore($storagePath);
        });

        // ForkManager
        $this->singleton(\SuperAgent\Fork\ForkManager::class, function () use ($config) {
            $cfg = $config->get('superagent.fork', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $executor = new \SuperAgent\Fork\ForkExecutor(
                defaultTimeout: (int) ($cfg['default_timeout'] ?? 300),
            );
            return new \SuperAgent\Fork\ForkManager($executor);
        });

        // DebateOrchestrator
        $this->singleton(\SuperAgent\Debate\DebateOrchestrator::class, function () use ($config) {
            $cfg = $config->get('superagent.debate', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            return new \SuperAgent\Debate\DebateOrchestrator(
                agentRunner: fn() => ['content' => '', 'cost' => 0.0, 'turns' => 0],
                defaultConfig: $cfg,
            );
        });

        // CostPredictor
        $this->singleton(\SuperAgent\CostPrediction\CostPredictor::class, function () use ($config) {
            $cfg = $config->get('superagent.cost_prediction', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $storagePath = $cfg['storage_path'] ?? $this->storagePath('superagent/cost_history');
            return new \SuperAgent\CostPrediction\CostPredictor(
                historyStore: new \SuperAgent\CostPrediction\CostHistoryStore($storagePath),
                config: $cfg,
            );
        });

        // PromptInjectionDetector
        $this->singleton(\SuperAgent\Guardrails\PromptInjectionDetector::class, function () {
            return new \SuperAgent\Guardrails\PromptInjectionDetector();
        });

        // CredentialPool
        $this->singleton(\SuperAgent\Providers\CredentialPool::class, function () use ($config) {
            $cfg = $config->get('superagent.credential_pool', []);
            $pool = \SuperAgent\Providers\CredentialPool::fromConfig($cfg);
            if (! empty($cfg)) {
                \SuperAgent\Providers\ProviderRegistry::setCredentialPool($pool);
            }
            return $pool;
        });

        // QueryComplexityRouter
        $this->singleton(\SuperAgent\Optimization\QueryComplexityRouter::class, function () use ($config) {
            $cfg = $config->get('superagent.optimization.query_complexity_routing', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $currentModel = $config->get('superagent.model', 'claude-sonnet-4-6');
            return \SuperAgent\Optimization\QueryComplexityRouter::fromConfig($currentModel);
        });

        // ContextCompressor
        $this->singleton(\SuperAgent\Optimization\ContextCompression\ContextCompressor::class, function () use ($config) {
            $cfg = $config->get('superagent.optimization.context_compression', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            return \SuperAgent\Optimization\ContextCompression\ContextCompressor::fromConfig();
        });

        // MemoryProviderManager
        $this->singleton(\SuperAgent\Memory\MemoryProviderManager::class, function () {
            $builtinProvider = new \SuperAgent\Memory\BuiltinMemoryProvider();
            return new \SuperAgent\Memory\MemoryProviderManager($builtinProvider);
        });

        // MiddlewarePipeline
        $this->singleton(\SuperAgent\Middleware\MiddlewarePipeline::class, function () {
            return new \SuperAgent\Middleware\MiddlewarePipeline();
        });

        // ToolResultCache
        $this->singleton(\SuperAgent\Tools\ToolResultCache::class, function () use ($config) {
            $cfg = $config->get('superagent.optimization.tool_cache', []);
            return new \SuperAgent\Tools\ToolResultCache(
                defaultTtlSeconds: (int) ($cfg['default_ttl'] ?? 300),
                maxEntries: (int) ($cfg['max_entries'] ?? 1000),
            );
        });

        // SelfHealingStrategy
        $this->singleton(\SuperAgent\Pipeline\SelfHealing\SelfHealingStrategy::class, function () use ($config) {
            $cfg = $config->get('superagent.self_healing', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            return new \SuperAgent\Pipeline\SelfHealing\SelfHealingStrategy(config: $cfg);
        });

        // PipelineEngine
        $this->singleton(\SuperAgent\Pipeline\PipelineEngine::class, function () use ($config) {
            $cfg = $config->get('superagent.pipelines', []);
            if (empty($cfg['enabled'])) {
                return null;
            }
            $files = $cfg['files'] ?? [];
            if (empty($files)) {
                return null;
            }
            $pipelineConfig = \SuperAgent\Pipeline\PipelineConfig::fromYamlFiles($files);
            return new \SuperAgent\Pipeline\PipelineEngine($pipelineConfig);
        });
    }

    // --- Private helpers ---

    private function resolveAlias(string $abstract): string
    {
        while (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }

    private function resolveStoragePath(): string
    {
        $home = \SuperAgent\Config\ConfigLoader::homeDir();

        return $home . '/.superagent/storage';
    }

    // --- Bootstrap ---

    /**
     * Bootstrap the standalone SuperAgent application.
     *
     * Creates the container, loads config, and registers core services.
     * Called by bin/superagent when running outside of Laravel.
     *
     * @param  string  $basePath   Project working directory (default: getcwd())
     * @param  array   $overrides  Config overrides from CLI flags
     */
    public static function bootstrap(string $basePath = '', array $overrides = []): self
    {
        $basePath = $basePath ?: getcwd();

        // 1. Create Application instance
        $app = new self($basePath);
        self::setInstance($app);

        // 2. Load configuration
        \SuperAgent\Config\ConfigLoader::load($basePath, $overrides);

        // 3. Ensure storage directory exists
        $storagePath = $app->storagePath();
        if (! is_dir($storagePath)) {
            @mkdir($storagePath, 0755, true);
        }

        // 4. Register core services
        $app->registerCoreServices();

        // 5. Register model aliases from config
        $modelAliases = ConfigRepository::getInstance()->get('superagent.model_aliases', []);
        if (! empty($modelAliases) && class_exists(\SuperAgent\Providers\ModelResolver::class)) {
            \SuperAgent\Providers\ModelResolver::registerAliases($modelAliases);
        }

        return $app;
    }
}
