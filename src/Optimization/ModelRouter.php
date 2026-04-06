<?php

declare(strict_types=1);

namespace SuperAgent\Optimization;

use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;

class ModelRouter
{
    public function __construct(
        private bool $enabled = true,
        private string $primaryModel = '',
        private string $fastModel = 'claude-haiku-4-5-20251001',
        private int $minTurnsBeforeDowngrade = 2,
        private int $consecutiveToolTurns = 0,
    ) {}

    /**
     * Create an instance from the application config, using the current model as the primary.
     */
    public static function fromConfig(string $currentModel): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.optimization.model_routing') ?? []) : [];
        } catch (\Throwable $e) {
            error_log('[SuperAgent] Config unavailable for ' . static::class . ': ' . $e->getMessage());
            $config = [];
        }

        return new self(
            enabled: $config['enabled'] ?? true,
            primaryModel: $currentModel,
            fastModel: $config['fast_model'] ?? 'claude-haiku-4-5-20251001',
            minTurnsBeforeDowngrade: $config['min_turns_before_downgrade'] ?? 2,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Decide which model to use for the next turn based on recent history.
     *
     * @param  array  $messages   Current message history
     * @param  int    $turnCount  Current turn number (1-based)
     * @return string|null  Model to use, or null to keep the default
     */
    public function route(array $messages, int $turnCount): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        // Never downgrade if the primary model is already a cheap model.
        if ($this->isAlreadyCheap()) {
            return null;
        }

        // Respect the warm-up window — always use primary for early turns.
        if ($turnCount <= $this->minTurnsBeforeDowngrade) {
            return null;
        }

        // If we have accumulated enough consecutive tool-only turns, downgrade.
        if ($this->consecutiveToolTurns >= 2) {
            return $this->fastModel;
        }

        // Default: use primary model.
        return null;
    }

    /**
     * Record the result of a turn for future routing decisions.
     * Call this after each provider response.
     */
    public function recordTurn(AssistantMessage $message): void
    {
        if (! $this->enabled) {
            return;
        }

        // If the assistant produced a non-tool response while on the fast model,
        // auto-upgrade back: reset the counter so `route()` returns null (primary).
        if (! $message->hasToolUse()) {
            $this->consecutiveToolTurns = 0;

            return;
        }

        $text = trim($message->text());

        // Pure tool-use turn: only tool_use blocks with no (or trivial) text.
        if ($message->hasToolUse() && strlen($text) < 20) {
            $this->consecutiveToolTurns++;

            return;
        }

        // Substantial text alongside tool use — this is a reasoning turn.
        $this->consecutiveToolTurns = 0;
    }

    /**
     * Determine whether the primary model is already a cheap/fast model.
     * Compares against the configured fast model and detects "haiku" in the name.
     */
    private function isAlreadyCheap(): bool
    {
        if ($this->primaryModel === $this->fastModel) {
            return true;
        }

        // Heuristic: if the primary model contains "haiku" it's already fast
        return str_contains(strtolower($this->primaryModel), 'haiku');
    }
}
