<?php

declare(strict_types=1);

namespace SuperAgent\Performance;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ToolResultMessage;

class AdaptiveMaxTokens
{
    public function __construct(
        private bool $enabled = true,
        private int $toolCallTokens = 2048,
        private int $reasoningTokens = 8192,
        private int $firstTurnTokens = 8192,
    ) {}

    /**
     * Create an instance from the application config.
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.performance.adaptive_max_tokens') ?? []) : [];
        } catch (\Throwable $e) {
            error_log('[SuperAgent] Config unavailable for ' . static::class . ': ' . $e->getMessage());
            $config = [];
        }

        return new self(
            enabled: $config['enabled'] ?? true,
            toolCallTokens: $config['tool_call_tokens'] ?? 2048,
            reasoningTokens: $config['reasoning_tokens'] ?? 8192,
            firstTurnTokens: $config['first_turn_tokens'] ?? 8192,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Determine appropriate max_tokens for the next turn.
     *
     * @param  array  $messages  Current message history
     * @param  int    $turnCount  Current turn (1-based)
     * @param  int    $defaultMaxTokens  The configured default
     * @return int  Adjusted max_tokens
     */
    public function adjust(array $messages, int $turnCount, int $defaultMaxTokens): int
    {
        if (! $this->enabled) {
            return $defaultMaxTokens;
        }

        // First turn: always return firstTurnTokens so the model has freedom to plan.
        if ($turnCount <= 1) {
            return min($this->firstTurnTokens, $defaultMaxTokens);
        }

        $lastMessage = $this->findLastRelevantMessage($messages);

        if ($lastMessage === null) {
            return min($this->reasoningTokens, $defaultMaxTokens);
        }

        // If last message was a ToolResultMessage, model will respond to tool
        // results -- could be either tool call or reasoning, use reasoning budget.
        if ($lastMessage instanceof ToolResultMessage) {
            return min($this->reasoningTokens, $defaultMaxTokens);
        }

        // If last message was an AssistantMessage, inspect its content.
        if ($lastMessage instanceof AssistantMessage) {
            $text = trim($lastMessage->text());
            $hasToolUse = $lastMessage->hasToolUse();

            // Pure tool_use (no text or negligible text): model is in a tool loop.
            if ($hasToolUse && mb_strlen($text) < 20) {
                return min($this->toolCallTokens, $defaultMaxTokens);
            }

            // Substantial text: model might reason again.
            return min($this->reasoningTokens, $defaultMaxTokens);
        }

        // Fallback for any other message type.
        return min($this->reasoningTokens, $defaultMaxTokens);
    }

    /**
     * Walk backwards through the message array to find the last AssistantMessage
     * or ToolResultMessage.
     */
    private function findLastRelevantMessage(array $messages): AssistantMessage|ToolResultMessage|null
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];

            if ($msg instanceof AssistantMessage || $msg instanceof ToolResultMessage) {
                return $msg;
            }
        }

        return null;
    }
}
