<?php

namespace SuperAgent;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Bridge\BridgeFactory;
use SuperAgent\Config\ExperimentalFeatures;
use SuperAgent\Providers\ModelResolver;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\AutoMode\TaskAnalyzer;
use SuperAgent\AutoMode\AutoModeAgent;
use SuperAgent\Tools\ToolLoader;
use SuperAgent\Tools\Builtin\AgentTool;

class Agent
{
    protected LLMProvider $provider;

    /** @var ToolInterface[] */
    protected array $tools = [];

    protected ?string $systemPrompt = null;

    protected int $maxTurns = 50;

    protected array $options = [];

    protected ?StreamingHandler $streamingHandler = null;

    /** @var string[]|null */
    protected ?array $allowedTools = null;

    /** @var string[] */
    protected array $deniedTools = [];

    protected float $maxBudgetUsd = 0.0;

    /** @var Message[] */
    protected array $messages = [];
    
    protected bool $autoMode = false;
    
    protected array $autoModeConfig = [];
    
    protected ?ToolLoader $toolLoader = null;

    public function __construct(array $config = [])
    {
        $this->provider = $this->resolveProvider($config);
        $this->maxTurns = $config['max_turns'] ?? static::config('superagent.agent.max_turns', 50);
        $this->maxBudgetUsd = (float) ($config['max_budget_usd'] ?? static::config('superagent.agent.max_budget_usd', 0));
        $this->systemPrompt = $config['system_prompt'] ?? null;
        $this->options = $config['options'] ?? [];
        
        // Auto-mode configuration
        $this->autoMode = $config['auto_mode'] ?? static::config('superagent.auto_mode.enabled', false);
        $this->autoModeConfig = $config['auto_mode_config'] ?? static::config('superagent.auto_mode', []);

        // Tool loading configuration
        $this->initializeTools($config);

        // Inject provider config into AgentTool so sub-agents share the same LLM credentials
        $this->injectProviderConfigIntoAgentTools($config);

        if (isset($config['allowed_tools'])) {
            $this->allowedTools = $config['allowed_tools'];
        }
        if (isset($config['denied_tools'])) {
            $this->deniedTools = $config['denied_tools'];
        }
        if (isset($config['streaming_handler'])) {
            $this->streamingHandler = $config['streaming_handler'];
        }
    }
    
    /**
     * Inject the parent provider config into any AgentTool instances so that
     * spawned sub-agents can create a real LLM connection.
     */
    protected function injectProviderConfigIntoAgentTools(array $config): void
    {
        // Collect only the scalar keys needed to reconstruct a provider in
        // a child process.  The 'provider' key might be an LLMProvider object
        // (not JSON-serializable) — replace it with the provider's string name.
        $providerConfig = array_intersect_key($config, array_flip([
            'provider', 'driver', 'api_key', 'model', 'base_url', 'max_tokens',
            'api_version', 'organization', 'app_name', 'site_url',
            'auth_mode', 'access_token', 'account_id', 'anthropic_beta',
        ]));

        // Ensure 'provider' is a serializable string, not an object
        if (isset($providerConfig['provider']) && $providerConfig['provider'] instanceof LLMProvider) {
            $providerConfig['provider'] = $providerConfig['provider']->name();
        }

        // If api_key was not in $config (e.g. it came from Laravel config()),
        // try to read it from the resolved provider so the child can authenticate.
        if (!isset($providerConfig['api_key']) && isset($this->provider)) {
            // The provider stores the key internally. We can't read private
            // fields, but we can pull it from the Laravel config if available.
            $name = $this->provider->name();
            $configKey = static::config("superagent.providers.{$name}.api_key");
            if ($configKey) {
                $providerConfig['api_key'] = $configKey;
            }
        }

        // Ensure provider name is always set
        if (!isset($providerConfig['provider']) || !is_string($providerConfig['provider'])) {
            $providerConfig['provider'] = $this->provider->name();
        }

        // Propagate model from the resolved provider if not already set
        if (!isset($providerConfig['model'])) {
            $providerConfig['model'] = $this->provider->getModel();
        }

        foreach ($this->tools as $tool) {
            if ($tool instanceof AgentTool) {
                $tool->setProviderConfig($providerConfig);
            }
        }
    }

    /**
     * Initialize tools with lazy loading support
     */
    protected function initializeTools(array $config): void
    {
        // If tools are explicitly provided, use them
        if (isset($config['tools'])) {
            $this->tools = $config['tools'];
            return;
        }
        
        // Initialize tool loader
        $this->toolLoader = new ToolLoader($config['tool_loader'] ?? []);
        
        // Determine which tools to load
        if (isset($config['load_tools'])) {
            if ($config['load_tools'] === true) {
                // Load default tools
                $this->tools = $this->toolLoader->getDefaultTools();
            } elseif (is_array($config['load_tools'])) {
                // Load specific tools
                $this->tools = $this->toolLoader->loadMany($config['load_tools']);
            } elseif ($config['load_tools'] === 'all') {
                // Load all available tools
                $this->tools = $this->toolLoader->getAllTools();
            } elseif ($config['load_tools'] === 'none' || $config['load_tools'] === false) {
                // No tools
                $this->tools = [];
            }
        } else {
            // Default behavior: load default tools if auto_load is enabled
            $autoLoad = $config['tool_loader']['auto_load'] ?? true;
            if ($autoLoad) {
                $this->tools = $this->toolLoader->getDefaultTools();
            }
        }
    }

    public function addTool(ToolInterface $tool): static
    {
        $this->tools[] = $tool;

        return $this;
    }

    public function withSystemPrompt(string $prompt): static
    {
        $this->systemPrompt = $prompt;

        return $this;
    }

    public function withModel(string $model): static
    {
        $this->provider->setModel(ModelResolver::resolve($model));

        return $this;
    }

    public function withMaxTurns(int $maxTurns): static
    {
        $this->maxTurns = $maxTurns;

        return $this;
    }

    public function withOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function withStreamingHandler(StreamingHandler $handler): static
    {
        $this->streamingHandler = $handler;

        return $this;
    }

    public function withAllowedTools(array $toolNames): static
    {
        $this->allowedTools = $toolNames;

        return $this;
    }

    public function withDeniedTools(array $toolNames): static
    {
        $this->deniedTools = $toolNames;

        return $this;
    }

    public function withMaxBudget(float $usd): static
    {
        $this->maxBudgetUsd = $usd;

        return $this;
    }
    
    public function withAutoMode(bool $enabled = true, array $config = []): static
    {
        $this->autoMode = $enabled;
        if (!empty($config)) {
            $this->autoModeConfig = array_merge($this->autoModeConfig, $config);
        }
        
        return $this;
    }
    
    /**
     * Load tools based on task content
     */
    public function loadToolsForTask(string $task): static
    {
        if ($this->toolLoader === null) {
            $this->toolLoader = new ToolLoader();
        }
        
        $this->tools = $this->toolLoader->loadForTask($task);
        
        return $this;
    }
    
    /**
     * Manually load specific tools
     */
    public function loadTools(array $toolNames): static
    {
        if ($this->toolLoader === null) {
            $this->toolLoader = new ToolLoader();
        }
        
        $this->tools = $this->toolLoader->loadMany($toolNames);
        
        return $this;
    }
    
    /**
     * Run the agent with automatic mode detection.
     * This is the primary entry point for agent execution.
     */
    public function run(string $prompt, array $options = []): AgentResult
    {
        // Merge caller-supplied options over the per-instance defaults so
        // callers of the non-auto path actually see their options applied
        // (pre-0.9.1 this silently dropped them). `idempotency_key` in
        // particular rides on this to surface on the returned AgentResult.
        if (! empty($options)) {
            $this->options = array_merge($this->options, $options);
        }

        // If auto-mode is enabled, use AutoModeAgent
        if ($this->autoMode) {
            $autoAgent = new AutoModeAgent(
                array_merge([
                    'provider' => $this->provider,
                    'auto_mode' => true,
                    'analyzer_config' => $this->autoModeConfig,
                    'tools' => $this->tools,
                    'system_prompt' => $this->systemPrompt,
                    'max_turns' => $this->maxTurns,
                    'max_budget_usd' => $this->maxBudgetUsd,
                ], $options)
            );

            return $autoAgent->run($prompt, $options);
        }

        // Otherwise use standard single-agent execution
        return $this->prompt($prompt);
    }

    /**
     * Run the agent with a prompt. Executes the full agentic loop.
     * @deprecated Use run() instead for auto-mode support
     */
    public function prompt(string $prompt, ?StreamingHandler $streamingHandler = null): AgentResult
    {
        $engine = $this->createEngine($streamingHandler);
        $engine->setMessages($this->messages);

        $lastMessage = null;
        $allResponses = [];

        foreach ($engine->run($prompt) as $assistantMessage) {
            $lastMessage = $assistantMessage;
            $allResponses[] = $assistantMessage;
        }

        $this->messages = $engine->getMessages();

        return new AgentResult(
            message: $lastMessage,
            allResponses: $allResponses,
            messages: $this->messages,
            totalCostUsd: $engine->getTotalCostUsd(),
            idempotencyKey: isset($this->options['idempotency_key']) && is_string($this->options['idempotency_key'])
                ? substr($this->options['idempotency_key'], 0, 80)
                : null,
        );
    }

    /**
     * Run the agent and yield each AssistantMessage as it streams.
     *
     * @return \Generator<int, AssistantMessage>
     */
    public function stream(string $prompt, ?StreamingHandler $streamingHandler = null): \Generator
    {
        $engine = $this->createEngine($streamingHandler);
        $engine->setMessages($this->messages);

        yield from $engine->run($prompt);

        $this->messages = $engine->getMessages();
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function clear(): static
    {
        $this->messages = [];

        return $this;
    }

    public function getProvider(): LLMProvider
    {
        return $this->provider;
    }

    protected function createEngine(?StreamingHandler $overrideHandler = null): QueryEngine
    {
        return new QueryEngine(
            provider: $this->provider,
            tools: $this->tools,
            systemPrompt: $this->systemPrompt,
            maxTurns: $this->maxTurns,
            options: $this->options,
            streamingHandler: $overrideHandler ?? $this->streamingHandler,
            allowedTools: $this->allowedTools,
            deniedTools: $this->deniedTools,
            maxBudgetUsd: $this->maxBudgetUsd,
        );
    }

    protected function resolveProvider(array $config): LLMProvider
    {
        if (isset($config['provider']) && $config['provider'] instanceof LLMProvider) {
            $provider = $config['provider'];

            return $this->maybeWrapWithBridge($provider, $config);
        }

        $providerName = $config['provider'] ?? static::config('superagent.default_provider', 'anthropic');
        $providerConfig = static::config("superagent.providers.{$providerName}", []);

        foreach (['api_key', 'model', 'base_url', 'max_tokens', 'auth_mode', 'access_token', 'account_id', 'anthropic_beta'] as $key) {
            if (isset($config[$key])) {
                $providerConfig[$key] = $config[$key];
            }
        }

        // Resolve model aliases (e.g., "opus" → "claude-opus-4-20250514")
        if (isset($providerConfig['model'])) {
            $providerConfig['model'] = ModelResolver::resolve($providerConfig['model']);
        }

        // Use 'driver' to determine which provider class to use,
        // falling back to the provider name itself.
        // This allows named instances like 'anthropic-proxy' with driver 'anthropic'.
        $driver = $providerConfig['driver'] ?? $providerName;

        $provider = ProviderRegistry::create($driver, $providerConfig);

        return $this->maybeWrapWithBridge($provider, $config);
    }

    /**
     * Wrap a non-Anthropic provider with Bridge enhancement if enabled.
     *
     * Priority (highest first):
     *  1. $config['bridge_mode'] = true/false  — explicit per-instance override
     *  2. config('superagent.bridge.auto_enhance') — config file setting
     *  3. ExperimentalFeatures::enabled('bridge_mode') — feature flag
     *
     * Anthropic providers are never wrapped (they natively have these optimizations).
     */
    protected function maybeWrapWithBridge(LLMProvider $provider, array $config): LLMProvider
    {
        // Anthropic never needs bridge enhancement
        if ($provider->name() === 'anthropic') {
            return $provider;
        }

        // Already wrapped
        if ($provider instanceof \SuperAgent\Bridge\EnhancedProvider) {
            return $provider;
        }

        // Resolve bridge_mode: explicit param > config auto_enhance > feature flag
        // Default is OFF to avoid surprising behavior — must be explicitly enabled.
        if (array_key_exists('bridge_mode', $config)) {
            $enabled = (bool) $config['bridge_mode'];
        } else {
            $autoEnhance = static::config('superagent.bridge.auto_enhance');
            if ($autoEnhance !== null) {
                $enabled = (bool) $autoEnhance;
            } elseif (function_exists('config') && function_exists('app') && app()->bound('config')) {
                $enabled = ExperimentalFeatures::enabled('bridge_mode');
            } else {
                $enabled = false; // No config available — default off
            }
        }

        if (! $enabled) {
            return $provider;
        }

        return BridgeFactory::wrapProvider($provider);
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && function_exists('app') && app()->bound('config')) {
            return config($key, $default);
        }

        return $default;
    }
}
