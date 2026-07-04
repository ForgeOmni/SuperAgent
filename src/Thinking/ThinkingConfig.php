<?php

declare(strict_types=1);

namespace SuperAgent\Thinking;

/**
 * Extended thinking configuration and capability detection ported from Claude Code.
 *
 * Three thinking modes:
 *  - adaptive: Model decides when/how much to think (default for 4.6+ models)
 *  - enabled:  Always think with a fixed budget
 *  - disabled: No thinking
 *
 * Ultrathink trigger: the keyword "ultrathink" in user messages sets
 * the thinking budget to maximum (available tokens).
 */
class ThinkingConfig
{
    /** Ultrathink keyword regex (word boundary, case-insensitive) */
    private const ULTRATHINK_PATTERN = '/\bultrathink\b/i';

    /** Default thinking budget tokens */
    private const DEFAULT_BUDGET_TOKENS = 10000;

    /** Maximum thinking budget tokens */
    private const MAX_BUDGET_TOKENS = 128000;

    private string $mode; // 'adaptive', 'enabled', 'disabled'
    private int $budgetTokens;

    public function __construct(
        string $mode = 'disabled',
        int $budgetTokens = self::DEFAULT_BUDGET_TOKENS,
    ) {
        $this->mode = $mode;
        $this->budgetTokens = $budgetTokens;
    }

    /**
     * Create adaptive thinking config (model decides when to think).
     */
    public static function adaptive(): self
    {
        return new self('adaptive');
    }

    /**
     * Create enabled thinking config with budget.
     */
    public static function enabled(int $budgetTokens = self::DEFAULT_BUDGET_TOKENS): self
    {
        return new self('enabled', $budgetTokens);
    }

    /**
     * Create disabled thinking config.
     */
    public static function disabled(): self
    {
        return new self('disabled');
    }

    /**
     * Create thinking config from environment and settings.
     */
    public static function fromEnvironment(array $settings = []): self
    {
        // Check MAX_THINKING_TOKENS env var
        $envBudget = $_ENV['MAX_THINKING_TOKENS'] ?? getenv('MAX_THINKING_TOKENS');
        if ($envBudget !== false && $envBudget !== '' && (int) $envBudget > 0) {
            return self::enabled((int) $envBudget);
        }

        // Check settings
        if (isset($settings['alwaysThinkingEnabled']) && $settings['alwaysThinkingEnabled'] === false) {
            return self::disabled();
        }

        // Default: adaptive for capable models
        return self::adaptive();
    }

    /**
     * Check if the "ultrathink" keyword is present in text.
     */
    public static function hasUltrathinkKeyword(string $text): bool
    {
        return (bool) preg_match(self::ULTRATHINK_PATTERN, $text);
    }

    /**
     * Find positions of ultrathink keyword in text.
     *
     * @return array<array{word: string, start: int, end: int}>
     */
    public static function findThinkingTriggerPositions(string $text): array
    {
        $positions = [];
        if (preg_match_all(self::ULTRATHINK_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $positions[] = [
                    'word' => $match[0],
                    'start' => $match[1],
                    'end' => $match[1] + strlen($match[0]),
                ];
            }
        }
        return $positions;
    }

    /**
     * Apply ultrathink if keyword detected and feature enabled: boost budget to max.
     */
    public function maybeApplyUltrathink(string $userMessage): self
    {
        if (self::hasUltrathinkKeyword($userMessage)
            && \SuperAgent\Config\ExperimentalFeatures::enabled('ultrathink')) {
            return new self('enabled', self::MAX_BUDGET_TOKENS);
        }
        return $this;
    }

    /**
     * Check if a model supports extended thinking.
     */
    public static function modelSupportsThinking(string $model): bool
    {
        $model = strtolower($model);

        // Claude 4+ models support thinking. Fable 5 is a separate family that
        // also thinks (always-on, adaptive).
        $thinkingModels = [
            'claude-4', 'claude-opus-4', 'claude-sonnet-4',
            'claude-haiku-4', 'claude-fable', 'fable-5',
            'claude-sonnet-5', 'sonnet-5',
        ];

        foreach ($thinkingModels as $prefix) {
            if (str_contains($model, $prefix)) {
                return true;
            }
        }

        // Claude 3.5 Sonnet v2 supports thinking
        if (str_contains($model, 'claude-3-5-sonnet') && str_contains($model, '2024')) {
            return true;
        }

        return false;
    }

    /**
     * Check if a model uses the adaptive-thinking surface.
     *
     * These models take `thinking: {type: "adaptive"}` and REJECT an explicit
     * `budget_tokens` — Opus 4.7 / 4.8 and Fable 5 return a 400 if `budget_tokens`
     * is sent, and it is deprecated on Opus 4.6 / Sonnet 4.6. On Fable 5 thinking
     * is always on; passing `type:"disabled"` also 400s, so we simply omit the
     * budget and let the server manage depth (steer via `output_config.effort`).
     */
    public static function modelSupportsAdaptiveThinking(string $model): bool
    {
        $model = strtolower($model);

        // 4.6+ Opus/Sonnet and the Claude 5 generation (Fable 5, Sonnet 5) are
        // adaptive-only.
        $adaptiveModels = [
            'opus-4-6', 'opus-4-7', 'opus-4-8',
            'sonnet-4-6',
            'claude-fable', 'fable-5',
            'claude-sonnet-5', 'sonnet-5',
        ];

        foreach ($adaptiveModels as $modelId) {
            if (str_contains($model, $modelId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the thinking parameter for the API request body.
     *
     * @return array|null Thinking parameter or null if disabled
     */
    public function toApiParameter(string $model): ?array
    {
        if ($this->mode === 'disabled') {
            return null;
        }

        if (!self::modelSupportsThinking($model)) {
            return null;
        }

        // Adaptive-only models (Opus 4.6/4.7/4.8, Sonnet 4.6, Fable 5) take
        // `{type: adaptive}` and 400 on an explicit `budget_tokens`. This holds
        // even when the caller asked for `enabled` with a fixed budget — the
        // budget is unsupported on these models, so honour the intent to think
        // by switching to adaptive rather than emitting a request that 400s.
        if (self::modelSupportsAdaptiveThinking($model)) {
            return ['type' => 'adaptive'];
        }

        // Older models: fixed-budget extended thinking.
        return [
            'type' => 'enabled',
            'budget_tokens' => $this->budgetTokens,
        ];
    }

    // Getters

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getBudgetTokens(): int
    {
        return $this->budgetTokens;
    }

    public function isEnabled(): bool
    {
        return $this->mode !== 'disabled';
    }

    public function isAdaptive(): bool
    {
        return $this->mode === 'adaptive';
    }
}
