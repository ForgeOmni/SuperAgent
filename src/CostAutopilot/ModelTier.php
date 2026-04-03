<?php

declare(strict_types=1);

namespace SuperAgent\CostAutopilot;

/**
 * Defines a model tier in the cost optimization hierarchy.
 *
 * Models are organized into tiers ordered by cost (highest to lowest).
 * When budget thresholds are crossed, the autopilot downgrades to a cheaper tier.
 */
class ModelTier
{
    /**
     * @param string $name Tier name (e.g., "premium", "standard", "economy")
     * @param string $model Model identifier for the provider
     * @param float $costPerMillionInput Input token price per million
     * @param float $costPerMillionOutput Output token price per million
     * @param int $priority Lower = cheaper. Used for ordering tiers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $model,
        public readonly float $costPerMillionInput,
        public readonly float $costPerMillionOutput,
        public readonly int $priority = 0,
    ) {}

    /**
     * Blended cost per million tokens (average of input and output).
     */
    public function blendedCostPerMillion(): float
    {
        return ($this->costPerMillionInput + $this->costPerMillionOutput) / 2;
    }

    /**
     * Whether this tier is free (e.g., local Ollama models).
     */
    public function isFree(): bool
    {
        return $this->costPerMillionInput <= 0 && $this->costPerMillionOutput <= 0;
    }

    /**
     * Create predefined Anthropic tier hierarchy.
     *
     * @return ModelTier[]
     */
    public static function anthropicTiers(): array
    {
        return [
            new self('opus', 'claude-opus-4-20250514', 15.0, 75.0, 30),
            new self('sonnet', 'claude-sonnet-4-20250514', 3.0, 15.0, 20),
            new self('haiku', 'claude-haiku-4-5-20251001', 0.80, 4.0, 10),
        ];
    }

    /**
     * Create predefined OpenAI tier hierarchy.
     *
     * @return ModelTier[]
     */
    public static function openaiTiers(): array
    {
        return [
            new self('gpt4o', 'gpt-4o', 2.50, 10.0, 30),
            new self('gpt4o-mini', 'gpt-4o-mini', 0.15, 0.60, 20),
            new self('gpt35', 'gpt-3.5-turbo', 0.50, 1.50, 10),
        ];
    }
}
