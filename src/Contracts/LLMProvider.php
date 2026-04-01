<?php

namespace SuperAgent\Contracts;

use Generator;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;

interface LLMProvider
{
    /**
     * Send a chat request and return a streaming generator of response chunks.
     *
     * @param  array<Message>  $messages
     * @param  array<Tool>  $tools
     * @return Generator<int, AssistantMessage>
     */
    public function chat(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): Generator;

    /**
     * Convert internal messages to provider-specific API format.
     */
    public function formatMessages(array $messages): array;

    /**
     * Convert internal tool definitions to provider-specific format.
     */
    public function formatTools(array $tools): array;

    /**
     * Get the current model identifier.
     */
    public function getModel(): string;

    /**
     * Set the model to use.
     */
    public function setModel(string $model): void;

    /**
     * Get provider name (e.g. "anthropic", "openai").
     */
    public function name(): string;
}
