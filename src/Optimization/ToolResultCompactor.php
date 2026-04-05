<?php

declare(strict_types=1);

namespace SuperAgent\Optimization;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\ToolResultMessage;

class ToolResultCompactor
{
    public function __construct(
        private bool $enabled = true,
        private int $preserveRecentTurns = 2,
        private int $maxResultLength = 200,
    ) {}

    /**
     * Create an instance from the application config.
     */
    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config') ? (config('superagent.optimization.tool_result_compaction') ?? []) : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: $config['enabled'] ?? true,
            preserveRecentTurns: $config['preserve_recent_turns'] ?? 2,
            maxResultLength: $config['max_result_length'] ?? 200,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compact old tool results in the message array.
     * Returns a new array with old tool_result content truncated.
     * Does NOT modify the original messages.
     *
     * @param  array<\SuperAgent\Messages\Message>  $messages
     * @return array<\SuperAgent\Messages\Message>
     */
    public function compact(array $messages): array
    {
        if (! $this->enabled) {
            return $messages;
        }

        // Build a map of toolUseId => toolName from all AssistantMessage tool_use blocks.
        $toolNameMap = $this->buildToolNameMap($messages);

        // Count assistant messages to determine turn boundaries.
        $assistantIndices = [];
        foreach ($messages as $index => $message) {
            if ($message instanceof AssistantMessage) {
                $assistantIndices[] = $index;
            }
        }

        // Determine the cutoff: keep the last N assistant turns intact.
        $totalTurns = count($assistantIndices);
        if ($totalTurns <= $this->preserveRecentTurns) {
            // Not enough turns to compact anything.
            return $messages;
        }

        // The cutoff index: messages at or after this assistant message index are preserved.
        $cutoffAssistantIndex = $assistantIndices[$totalTurns - $this->preserveRecentTurns];

        // Build the new message array.
        $result = [];
        foreach ($messages as $index => $message) {
            if ($index >= $cutoffAssistantIndex || ! $message instanceof ToolResultMessage) {
                $result[] = $message;
                continue;
            }

            // This is a ToolResultMessage in the old region -- compact it.
            $result[] = $this->compactToolResultMessage($message, $toolNameMap);
        }

        return $result;
    }

    /**
     * Build a mapping of tool_use_id => tool_name from assistant messages.
     *
     * @param  array<\SuperAgent\Messages\Message>  $messages
     * @return array<string, string>
     */
    private function buildToolNameMap(array $messages): array
    {
        $map = [];
        foreach ($messages as $message) {
            if (! $message instanceof AssistantMessage) {
                continue;
            }
            foreach ($message->content as $block) {
                if ($block->type === 'tool_use' && $block->toolUseId !== null && $block->toolName !== null) {
                    $map[$block->toolUseId] = $block->toolName;
                }
            }
        }

        return $map;
    }

    /**
     * Create a new ToolResultMessage with compacted content blocks.
     *
     * @param  array<string, string>  $toolNameMap
     */
    private function compactToolResultMessage(ToolResultMessage $message, array $toolNameMap): ToolResultMessage
    {
        $newBlocks = [];

        foreach ($message->content as $block) {
            // Keep error results intact -- they're usually short and important.
            if ($block->isError) {
                $newBlocks[] = $block;
                continue;
            }

            $contentStr = $block->content ?? '';

            // If already short enough, keep as-is.
            if (mb_strlen($contentStr) <= $this->maxResultLength) {
                $newBlocks[] = $block;
                continue;
            }

            $toolName = $toolNameMap[$block->toolUseId] ?? 'unknown';
            $truncated = mb_substr($contentStr, 0, $this->maxResultLength);
            $compactedContent = "[Compacted] {$toolName}: {$truncated}...";

            $newBlocks[] = ContentBlock::toolResult(
                $block->toolUseId,
                $compactedContent,
                $block->isError ?? false,
            );
        }

        return new ToolResultMessage($newBlocks);
    }
}
