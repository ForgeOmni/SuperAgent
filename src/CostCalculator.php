<?php

namespace SuperAgent;

use SuperAgent\Messages\Usage;

class CostCalculator
{
    /**
     * Pricing per million tokens [input, output] in USD.
     */
    protected static array $pricing = [
        // Anthropic Claude 4.6 models (latest)
        'claude-sonnet-4-6-20250627' => ['input' => 3.0, 'output' => 15.0],
        'claude-opus-4-6-20250514'   => ['input' => 15.0, 'output' => 75.0],
        'claude-haiku-4-5-20251001'  => ['input' => 0.80, 'output' => 4.0],
        // Anthropic Claude 4.x models
        'claude-sonnet-4-20250514' => ['input' => 3.0, 'output' => 15.0],
        'claude-opus-4-20250514'   => ['input' => 15.0, 'output' => 75.0],
        // Anthropic Claude 3.x models (legacy)
        'claude-haiku-3-5-20241022' => ['input' => 0.80, 'output' => 4.0],
        'claude-3-5-sonnet-20241022' => ['input' => 3.0, 'output' => 15.0],
        'claude-3-opus-20240229' => ['input' => 15.0, 'output' => 75.0],
        'claude-3-sonnet-20240229' => ['input' => 3.0, 'output' => 15.0],
        'claude-3-haiku-20240307' => ['input' => 0.25, 'output' => 1.25],
        
        // OpenAI GPT models
        'gpt-4o' => ['input' => 2.50, 'output' => 10.0],
        'gpt-4o-2024-11-20' => ['input' => 2.50, 'output' => 10.0],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4-turbo' => ['input' => 10.0, 'output' => 30.0],
        'gpt-4-turbo-preview' => ['input' => 10.0, 'output' => 30.0],
        'gpt-4' => ['input' => 30.0, 'output' => 60.0],
        'gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
        'gpt-3.5-turbo-16k' => ['input' => 3.0, 'output' => 4.0],
        
        // OpenRouter models (varied pricing)
        'anthropic/claude-3-5-sonnet' => ['input' => 3.0, 'output' => 15.0],
        'anthropic/claude-3-opus' => ['input' => 15.0, 'output' => 75.0],
        'anthropic/claude-3-sonnet' => ['input' => 3.0, 'output' => 15.0],
        'anthropic/claude-3-haiku' => ['input' => 0.25, 'output' => 1.25],
        'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.0],
        'openai/gpt-4-turbo' => ['input' => 10.0, 'output' => 30.0],
        'openai/gpt-3.5-turbo' => ['input' => 0.50, 'output' => 1.50],
        'google/gemini-pro' => ['input' => 0.50, 'output' => 1.50],
        'google/gemini-pro-1.5' => ['input' => 3.50, 'output' => 10.50],
        'meta-llama/llama-3-70b-instruct' => ['input' => 0.80, 'output' => 0.80],
        'meta-llama/llama-3-8b-instruct' => ['input' => 0.10, 'output' => 0.10],
        'mistralai/mistral-large' => ['input' => 8.0, 'output' => 24.0],
        'mistralai/mixtral-8x7b-instruct' => ['input' => 0.60, 'output' => 0.60],
        
        // AWS Bedrock models
        'anthropic.claude-sonnet-4-6-20250627-v1:0' => ['input' => 3.0, 'output' => 15.0],
        'anthropic.claude-opus-4-6-20250514-v1:0'   => ['input' => 15.0, 'output' => 75.0],
        'anthropic.claude-haiku-4-5-20251001-v1:0'   => ['input' => 0.80, 'output' => 4.0],
        'anthropic.claude-sonnet-4-20250514-v1:0' => ['input' => 3.0, 'output' => 15.0],
        'anthropic.claude-opus-4-20250514-v1:0'   => ['input' => 15.0, 'output' => 75.0],
        'anthropic.claude-3-5-sonnet-20241022-v2:0' => ['input' => 3.0, 'output' => 15.0],
        'anthropic.claude-3-sonnet-20240229-v1:0' => ['input' => 3.0, 'output' => 15.0],
        'anthropic.claude-3-haiku-20240307-v1:0' => ['input' => 0.25, 'output' => 1.25],
        'anthropic.claude-3-opus-20240229-v1:0' => ['input' => 15.0, 'output' => 75.0],
        'amazon.titan-text-express-v1' => ['input' => 0.13, 'output' => 0.17],
        'amazon.titan-text-lite-v1' => ['input' => 0.075, 'output' => 0.10],
        'meta.llama3-1-70b-instruct-v1:0' => ['input' => 0.99, 'output' => 0.99],
        'meta.llama3-1-8b-instruct-v1:0' => ['input' => 0.22, 'output' => 0.22],
        'mistral.mistral-7b-instruct-v0:2' => ['input' => 0.15, 'output' => 0.20],
        'mistral.mixtral-8x7b-instruct-v0:1' => ['input' => 0.45, 'output' => 0.70],
        
        // Ollama models (local, free)
        'llama2' => ['input' => 0.0, 'output' => 0.0],
        'llama2:7b' => ['input' => 0.0, 'output' => 0.0],
        'llama2:13b' => ['input' => 0.0, 'output' => 0.0],
        'llama2:70b' => ['input' => 0.0, 'output' => 0.0],
        'llama3' => ['input' => 0.0, 'output' => 0.0],
        'llama3:8b' => ['input' => 0.0, 'output' => 0.0],
        'llama3:70b' => ['input' => 0.0, 'output' => 0.0],
        'mistral' => ['input' => 0.0, 'output' => 0.0],
        'mixtral' => ['input' => 0.0, 'output' => 0.0],
        'codellama' => ['input' => 0.0, 'output' => 0.0],
        'deepseek-coder' => ['input' => 0.0, 'output' => 0.0],
        'phi' => ['input' => 0.0, 'output' => 0.0],
        'orca-mini' => ['input' => 0.0, 'output' => 0.0],
        'vicuna' => ['input' => 0.0, 'output' => 0.0],
        'neural-chat' => ['input' => 0.0, 'output' => 0.0],
        'starling-lm' => ['input' => 0.0, 'output' => 0.0],
        'nous-hermes2' => ['input' => 0.0, 'output' => 0.0],
        'openhermes' => ['input' => 0.0, 'output' => 0.0],
        'zephyr' => ['input' => 0.0, 'output' => 0.0],
        'qwen' => ['input' => 0.0, 'output' => 0.0],
        'yi' => ['input' => 0.0, 'output' => 0.0],
    ];

    /**
     * Calculate cost in USD for a single API call.
     */
    public static function calculate(string $model, Usage $usage): float
    {
        $prices = static::resolve($model);

        $cost = ($usage->inputTokens * $prices['input'] / 1_000_000)
              + ($usage->outputTokens * $prices['output'] / 1_000_000);

        // Cache tokens: creation costs same as input, reads cost 90% less
        if ($usage->cacheCreationInputTokens) {
            $cost += $usage->cacheCreationInputTokens * ($prices['input'] * 1.25) / 1_000_000;
        }
        if ($usage->cacheReadInputTokens) {
            $cost += $usage->cacheReadInputTokens * ($prices['input'] * 0.10) / 1_000_000;
        }

        return $cost;
    }

    /**
     * Register or override pricing for a model.
     */
    public static function register(string $model, float $inputPerMillion, float $outputPerMillion): void
    {
        static::$pricing[$model] = ['input' => $inputPerMillion, 'output' => $outputPerMillion];
    }

    protected static function resolve(string $model): array
    {
        if (isset(static::$pricing[$model])) {
            return static::$pricing[$model];
        }

        // Fuzzy match: if model starts with a known prefix
        foreach (static::$pricing as $key => $prices) {
            if (str_starts_with($model, $key)) {
                return $prices;
            }
        }

        // Provider-based defaults
        if (str_contains($model, 'gpt')) {
            return ['input' => 2.50, 'output' => 10.0]; // GPT-4o pricing
        }
        
        if (str_contains($model, 'claude')) {
            return ['input' => 3.0, 'output' => 15.0]; // Sonnet pricing
        }
        
        if (str_contains($model, 'gemini')) {
            return ['input' => 0.50, 'output' => 1.50]; // Gemini Pro pricing
        }
        
        if (str_contains($model, 'llama') || str_contains($model, 'mistral')) {
            return ['input' => 0.50, 'output' => 0.50]; // Open model average
        }

        // Default fallback: sonnet pricing
        return ['input' => 3.0, 'output' => 15.0];
    }
    
    /**
     * Get all registered model pricings.
     */
    public static function getAllPricing(): array
    {
        return static::$pricing;
    }
    
    /**
     * Get pricing for a specific model.
     */
    public static function getPricing(string $model): array
    {
        return static::resolve($model);
    }
    
    /**
     * Format cost as a human-readable string.
     */
    public static function format(float $cost): string
    {
        if ($cost < 0.01) {
            return sprintf('$%.4f', $cost);
        } elseif ($cost < 1.0) {
            return sprintf('$%.3f', $cost);
        } else {
            return sprintf('$%.2f', $cost);
        }
    }
    
    /**
     * Calculate cost for multiple API calls.
     */
    public static function calculateBatch(string $model, array $usages): float
    {
        $total = 0.0;
        
        foreach ($usages as $usage) {
            $total += static::calculate($model, $usage);
        }
        
        return $total;
    }
    
    /**
     * Estimate cost based on character count.
     */
    public static function estimate(string $model, int $inputChars, int $outputChars): float
    {
        // Rough estimate: 1 token ≈ 4 characters
        $inputTokens = (int) ($inputChars / 4);
        $outputTokens = (int) ($outputChars / 4);
        
        $usage = new Usage($inputTokens, $outputTokens);
        
        return static::calculate($model, $usage);
    }
}
