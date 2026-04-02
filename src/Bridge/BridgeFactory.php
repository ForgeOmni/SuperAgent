<?php

declare(strict_types=1);

namespace SuperAgent\Bridge;

use SuperAgent\Bridge\Enhancers\EnhancerInterface;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\BedrockProvider;
use SuperAgent\Providers\OllamaProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\OpenRouterProvider;

/**
 * Factory that creates an EnhancedProvider with the configured
 * backend provider and enhancer pipeline.
 */
class BridgeFactory
{
    /**
     * Create an EnhancedProvider for the given model.
     *
     * Resolves the backend provider from config, applies model mapping,
     * and attaches all enabled enhancers.
     */
    public static function createProvider(?string $requestedModel = null): EnhancedProvider
    {
        $inner = self::resolveInnerProvider($requestedModel);

        return self::wrapProvider($inner);
    }

    /**
     * Wrap an existing provider with the enhancer pipeline.
     *
     * Used by Agent::maybeWrapWithBridge() to enhance SDK-created providers.
     */
    public static function wrapProvider(LLMProvider $provider): EnhancedProvider
    {
        $enhancers = self::resolveEnhancers();

        return new EnhancedProvider($provider, $enhancers);
    }

    /**
     * Resolve the inner (actual) LLM provider based on bridge config.
     */
    private static function resolveInnerProvider(?string $requestedModel): LLMProvider
    {
        $providerName = self::cfg('superagent.bridge.provider', 'openai');
        $providerConfig = self::cfg("superagent.providers.{$providerName}", []);

        // Apply model mapping
        if ($requestedModel !== null) {
            $modelMap = self::cfg('superagent.bridge.model_map', []);
            $resolvedModel = $modelMap[$requestedModel] ?? $requestedModel;
            $providerConfig['model'] = $resolvedModel;
        }

        return match ($providerName) {
            'openai' => new OpenAIProvider($providerConfig),
            'openrouter' => new OpenRouterProvider($providerConfig),
            'bedrock' => new BedrockProvider($providerConfig),
            'ollama' => new OllamaProvider($providerConfig),
            default => throw new \InvalidArgumentException(
                "Unsupported bridge provider: {$providerName}. "
                . "Anthropic does not need bridge enhancement — it natively has these optimizations."
            ),
        };
    }

    /**
     * Build the enhancer pipeline from config toggles.
     *
     * @return EnhancerInterface[]
     */
    private static function resolveEnhancers(): array
    {
        $enhancers = [];
        $config = self::cfg('superagent.bridge.enhancers', []);

        // Each enhancer is loaded only if its config flag is true.
        // Order matters: system prompt first, then compaction, then security, etc.

        $registry = [
            'system_prompt' => \SuperAgent\Bridge\Enhancers\SystemPromptEnhancer::class,
            'context_compaction' => \SuperAgent\Bridge\Enhancers\ContextCompactionEnhancer::class,
            'bash_security' => \SuperAgent\Bridge\Enhancers\BashSecurityEnhancer::class,
            'memory_injection' => \SuperAgent\Bridge\Enhancers\MemoryInjectionEnhancer::class,
            'tool_schema' => \SuperAgent\Bridge\Enhancers\ToolSchemaEnhancer::class,
            'tool_summary' => \SuperAgent\Bridge\Enhancers\ToolSummaryEnhancer::class,
            'token_budget' => \SuperAgent\Bridge\Enhancers\TokenBudgetEnhancer::class,
            'cost_tracking' => \SuperAgent\Bridge\Enhancers\CostTrackingEnhancer::class,
        ];

        foreach ($registry as $key => $class) {
            if (! empty($config[$key]) && class_exists($class)) {
                $enhancers[] = new $class();
            }
        }

        return $enhancers;
    }

    /**
     * Safe config() wrapper that returns the default when Laravel isn't booted.
     */
    private static function cfg(string $key, mixed $default = null): mixed
    {
        if (function_exists('config') && function_exists('app') && app()->bound('config')) {
            return config($key, $default);
        }

        return $default;
    }
}
