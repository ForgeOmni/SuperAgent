<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;

/**
 * Collects StreamEvent listeners and dispatches events to all of them.
 *
 * Also exposes a StreamingHandler adapter so the existing QueryEngine
 * can emit structured events without code changes.
 */
class StreamEventEmitter
{
    /** @var array<int, \Closure(StreamEvent): void> */
    private array $listeners = [];

    /** @var StreamEvent[] */
    private array $history = [];

    private bool $recordHistory;

    public function __construct(bool $recordHistory = false)
    {
        $this->recordHistory = $recordHistory;
    }

    /**
     * Register a listener. Returns an unsubscribe ID.
     *
     * @param \Closure(StreamEvent): void $listener
     */
    public function on(\Closure $listener): int
    {
        $id = count($this->listeners);
        $this->listeners[$id] = $listener;
        return $id;
    }

    /**
     * Remove a listener by ID.
     */
    public function off(int $id): void
    {
        unset($this->listeners[$id]);
    }

    /**
     * Emit an event to all listeners.
     */
    public function emit(StreamEvent $event): void
    {
        if ($this->recordHistory) {
            $this->history[] = $event;
        }

        foreach ($this->listeners as $listener) {
            $listener($event);
        }
    }

    /**
     * Get the recorded event history (only when recordHistory=true).
     *
     * @return StreamEvent[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Clear recorded history.
     */
    public function clearHistory(): void
    {
        $this->history = [];
    }

    /**
     * Create a StreamingHandler that bridges to this emitter.
     *
     * This allows the existing QueryEngine + Providers to emit
     * structured StreamEvents without any internal changes.
     */
    public function toStreamingHandler(): StreamingHandler
    {
        return new StreamingHandler(
            onText: function (string $delta, string $fullText) {
                $this->emit(new TextDeltaEvent($delta));
            },
            onThinking: function (string $delta, string $fullThinking) {
                $this->emit(new ThinkingDeltaEvent($delta));
            },
            onToolUse: function (ContentBlock $block) {
                $this->emit(new ToolStartedEvent(
                    toolName: $block->toolName,
                    toolUseId: $block->toolUseId,
                    toolInput: $block->toolInput ?? [],
                ));
            },
            onToolResult: function (string $toolUseId, string $toolName, string $result, bool $isError) {
                $this->emit(new ToolCompletedEvent(
                    toolName: $toolName,
                    toolUseId: $toolUseId,
                    output: $result,
                    isError: $isError,
                ));
            },
            onTurn: function (AssistantMessage $message, int $turnNumber) {
                $this->emit(new TurnCompleteEvent(
                    message: $message,
                    turnNumber: $turnNumber,
                    usage: $message->usage ? [
                        'input_tokens' => $message->usage->inputTokens ?? 0,
                        'output_tokens' => $message->usage->outputTokens ?? 0,
                    ] : null,
                ));
            },
            onFinalMessage: function (AssistantMessage $message) {
                // AgentCompleteEvent is emitted by the HarnessLoop, not here
            },
        );
    }
}
