<?php

namespace SuperAgent;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

/**
 * Callbacks for real-time streaming events during an agent run.
 *
 * Usage:
 *   $handler = new StreamingHandler(
 *       onText: function (string $delta, string $fullText) {
 *           echo $delta; // token-by-token output
 *       },
 *       onToolUse: function (ContentBlock $block) {
 *           echo "Calling tool: {$block->toolName}\n";
 *       },
 *   );
 *   $agent->prompt('hello', streamingHandler: $handler);
 */
class StreamingHandler
{
    /**
     * @param  ?\Closure(string $delta, string $fullText): void  $onText
     *     Called for each text chunk as it streams in.
     *
     * @param  ?\Closure(string $delta, string $fullThinking): void  $onThinking
     *     Called for each thinking chunk.
     *
     * @param  ?\Closure(ContentBlock $block): void  $onToolUse
     *     Called when a tool_use block is fully received.
     *
     * @param  ?\Closure(string $toolUseId, string $toolName, string $result, bool $isError): void  $onToolResult
     *     Called after a tool finishes execution.
     *
     * @param  ?\Closure(AssistantMessage $message, int $turnNumber): void  $onTurn
     *     Called after each complete LLM turn (including tool-use turns).
     *
     * @param  ?\Closure(AssistantMessage $finalMessage): void  $onFinalMessage
     *     Called once when the agent loop finishes.
     *
     * @param  ?\Closure(string $event, array $data): void  $onRawEvent
     *     Called for every raw SSE event from the provider.
     */
    public function __construct(
        public readonly ?\Closure $onText = null,
        public readonly ?\Closure $onThinking = null,
        public readonly ?\Closure $onToolUse = null,
        public readonly ?\Closure $onToolResult = null,
        public readonly ?\Closure $onTurn = null,
        public readonly ?\Closure $onFinalMessage = null,
        public readonly ?\Closure $onRawEvent = null,
    ) {
    }

    public function emitText(string $delta, string $fullText): void
    {
        if ($this->onText) {
            ($this->onText)($delta, $fullText);
        }
    }

    public function emitThinking(string $delta, string $fullThinking): void
    {
        if ($this->onThinking) {
            ($this->onThinking)($delta, $fullThinking);
        }
    }

    public function emitToolUse(ContentBlock $block): void
    {
        if ($this->onToolUse) {
            ($this->onToolUse)($block);
        }
    }

    public function emitToolResult(string $toolUseId, string $toolName, string $result, bool $isError): void
    {
        if ($this->onToolResult) {
            ($this->onToolResult)($toolUseId, $toolName, $result, $isError);
        }
    }

    public function emitTurn(AssistantMessage $message, int $turnNumber): void
    {
        if ($this->onTurn) {
            ($this->onTurn)($message, $turnNumber);
        }
    }

    public function emitFinalMessage(AssistantMessage $message): void
    {
        if ($this->onFinalMessage) {
            ($this->onFinalMessage)($message);
        }
    }

    public function emitRawEvent(string $event, array $data): void
    {
        if ($this->onRawEvent) {
            ($this->onRawEvent)($event, $data);
        }
    }
}
