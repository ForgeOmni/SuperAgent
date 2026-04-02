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
        $enhancers = self::resolveEnhancers();

        return new EnhancedProvider($inner, $enhancers);
    }

    /**
     * Resolve the inner (actual) LLM provider based on bridge config.
     */
    private static function resolveInnerProvider(?string $requestedModel): LLMProvider
    {
        $providerName = config('superagent.bridge.provider', 'openai');
        $providerConfig = config("superagent.providers.{$providerName}", []);

        // Apply model mapping
        if ($requestedModel !== null) {
            $modelMap = config('superagent.bridge.model_map', []);
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
        $config = config('superagent.bridge.enhancers', []);

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
}
