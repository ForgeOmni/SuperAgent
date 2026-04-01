<?php

namespace SuperAgent;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Contracts\ToolInterface;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\BedrockProvider;
use SuperAgent\Providers\OllamaProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\OpenRouterProvider;

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

    public function __construct(array $config = [])
    {
        $this->provider = $this->resolveProvider($config);
        $this->maxTurns = $config['max_turns'] ?? static::config('superagent.agent.max_turns', 50);
        $this->maxBudgetUsd = (float) ($config['max_budget_usd'] ?? static::config('superagent.agent.max_budget_usd', 0));
        $this->systemPrompt = $config['system_prompt'] ?? null;
        $this->options = $config['options'] ?? [];

        if (isset($config['tools'])) {
            $this->tools = $config['tools'];
        }
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
        $this->provider->setModel($model);

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

    /**
     * Run the agent with a prompt. Executes the full agentic loop.
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
            return $config['provider'];
        }

        $providerName = $config['provider'] ?? static::config('superagent.default_provider', 'anthropic');
        $providerConfig = static::config("superagent.providers.{$providerName}", []);

        foreach (['api_key', 'model', 'base_url', 'max_tokens'] as $key) {
            if (isset($config[$key])) {
                $providerConfig[$key] = $config[$key];
            }
        }

        // Use 'driver' to determine which provider class to use,
        // falling back to the provider name itself.
        // This allows named instances like 'anthropic-proxy' with driver 'anthropic'.
        $driver = $providerConfig['driver'] ?? $providerName;

        return match ($driver) {
            'anthropic' => new AnthropicProvider($providerConfig),
            'openai' => new OpenAIProvider($providerConfig),
            'openrouter' => new OpenRouterProvider($providerConfig),
            'bedrock' => new BedrockProvider($providerConfig),
            'ollama' => new OllamaProvider($providerConfig),
            default => throw new \InvalidArgumentException("Unsupported provider driver: {$driver}"),
        };
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && function_exists('app') && app()->bound('config')) {
            return config($key, $default);
        }

        return $default;
    }
}
