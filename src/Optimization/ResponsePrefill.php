<?php

declare(strict_types=1);

namespace SuperAgent\Optimization;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

class ResponsePrefill
{
    public function __construct(
        private bool $enabled = true,
    ) {}

    /**
     * Create an instance from the application config.
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.optimization.response_prefill') ?? []) : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: $config['enabled'] ?? true,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Generate prefill text based on context.
     * Returns null if no prefill should be used.
     *
     * @param  array<\SuperAgent\Messages\Message>  $messages  Current message history (Message objects)
     * @param  array  $tools  Available tools
     * @return string|null  Prefill text to inject
     */
    public function generate(array $messages, array $tools): ?string
    {
        if (! $this->enabled || empty($messages)) {
            return null;
        }

        $lastMessage = end($messages);

        // After tool results: don't prefill — model needs to decide next action.
        if ($lastMessage instanceof ToolResultMessage) {
            // Exception: if the model has been doing many consecutive tool calls,
            // nudge it toward a summary/conclusion.
            if ($this->countConsecutiveToolRoundtrips($messages) >= 3) {
                return "I'll";
            }

            return null;
        }

        // First turn or user message with tools: too unpredictable to prefill.
        if ($lastMessage instanceof UserMessage && ! empty($tools)) {
            return null;
        }

        return null;
    }

    /**
     * Count the number of consecutive assistant-tool_use / tool_result round-trips
     * at the tail of the message history.
     *
     * A round-trip is an AssistantMessage with tool_use followed by a ToolResultMessage.
     *
     * @param  array<\SuperAgent\Messages\Message>  $messages
     */
    private function countConsecutiveToolRoundtrips(array $messages): int
    {
        $count = 0;
        $i = count($messages) - 1;

        while ($i >= 1) {
            $current = $messages[$i];
            $previous = $messages[$i - 1];

            if (
                $current instanceof ToolResultMessage
                && $previous instanceof AssistantMessage
                && $previous->hasToolUse()
            ) {
                $count++;
                $i -= 2;
            } else {
                break;
            }
        }

        return $count;
    }
}
