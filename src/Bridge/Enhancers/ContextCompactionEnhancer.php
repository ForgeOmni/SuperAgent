<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;

/**
 * Applies CC's tiered context compaction to reduce message size
 * before sending to the LLM provider.
 *
 * Strategy (lightweight, no LLM call needed):
 * 1. Truncate old tool result content that exceeds a threshold
 * 2. Keep recent messages intact
 * 3. Replace truncated content with a brief indicator
 *
 * This mirrors MicroCompressor's approach but operates directly on
 * the internal Message array without needing the full Context subsystem.
 */
class ContextCompactionEnhancer implements EnhancerInterface
{
    /** Keep the most recent N messages uncompressed */
    private int $keepRecent;

    /** Truncate tool results longer than this (chars) */
    private int $maxToolResultChars;

    /** Only trigger compaction if total messages exceed this */
    private int $minMessages;

    public function __construct(
        int $keepRecent = 10,
        int $maxToolResultChars = 2000,
        int $minMessages = 15,
    ) {
        $this->keepRecent = $keepRecent;
        $this->maxToolResultChars = $maxToolResultChars;
        $this->minMessages = $minMessages;
    }

    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        $count = count($messages);

        if ($count < $this->minMessages) {
            return;
        }

        // Only compact messages outside the "keep recent" window
        $compactBoundary = max(0, $count - $this->keepRecent);

        for ($i = 0; $i < $compactBoundary; $i++) {
            $msg = $messages[$i];

            if ($msg instanceof ToolResultMessage) {
                $messages[$i] = $this->compactToolResult($msg);
            } elseif ($msg instanceof AssistantMessage) {
                $messages[$i] = $this->compactAssistantToolResults($msg);
            }
        }
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        return $message; // No response-side enhancement
    }

    /**
     * Truncate tool result content blocks that exceed the threshold.
     */
    private function compactToolResult(ToolResultMessage $msg): ToolResultMessage
    {
        $compacted = false;
        $results = [];

        foreach ($msg->content as $block) {
            $content = $block->content ?? '';

            if (strlen($content) > $this->maxToolResultChars) {
                $results[] = [
                    'tool_use_id' => $block->toolUseId ?? '',
                    'content' => mb_substr($content, 0, $this->maxToolResultChars)
                        . "\n\n[... truncated by bridge compaction, "
                        . number_format(strlen($content)) . " chars total]",
                    'is_error' => $block->isError ?? false,
                ];
                $compacted = true;
            } else {
                $results[] = [
                    'tool_use_id' => $block->toolUseId ?? '',
                    'content' => $content,
                    'is_error' => $block->isError ?? false,
                ];
            }
        }

        return $compacted ? ToolResultMessage::fromResults($results) : $msg;
    }

    /**
     * For AssistantMessages, we don't truncate (they're usually compact).
     * But if there are thinking blocks, strip them from older messages.
     */
    private function compactAssistantToolResults(AssistantMessage $msg): AssistantMessage
    {
        $hasThinking = false;
        foreach ($msg->content as $block) {
            if ($block->type === 'thinking') {
                $hasThinking = true;
                break;
            }
        }

        if (! $hasThinking) {
            return $msg;
        }

        // Strip thinking blocks from old messages to save context
        $compacted = new AssistantMessage();
        $compacted->stopReason = $msg->stopReason;
        $compacted->usage = $msg->usage;

        foreach ($msg->content as $block) {
            if ($block->type !== 'thinking') {
                $compacted->content[] = $block;
            }
        }

        return $compacted;
    }
}
