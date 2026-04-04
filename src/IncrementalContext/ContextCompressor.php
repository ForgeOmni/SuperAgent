<?php

namespace SuperAgent\IncrementalContext;

use SuperAgent\Messages\Message;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Compresses context by summarising old / bulky messages.
 */
class ContextCompressor
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'compression_level' => 'balanced',
            'preserve_semantic_boundaries' => true,
        ], $config);
    }

    /**
     * Compress a full context array.
     * Keeps the first message and recent messages; summarises the middle.
     */
    public function compress(array $context): array
    {
        if (count($context) <= 4) {
            return $context;
        }

        $level = $this->config['compression_level'];

        // Keep first (system/initial) and last N messages depending on level
        $keepRecent = match ($level) {
            'minimal'    => 2,
            'aggressive' => 6,
            default      => 4, // balanced
        };

        $first = array_slice($context, 0, 1);
        $middle = array_slice($context, 1, count($context) - $keepRecent - 1);
        $recent = array_slice($context, -$keepRecent);

        if (empty($middle)) {
            return $context;
        }

        // Replace the middle with a summary placeholder
        $summary = $this->buildSummaryMessage($middle);

        return array_merge($first, [$summary], $recent);
    }

    /**
     * Compress an individual message (truncates overly long tool output, etc.)
     */
    public function compressMessage(Message $message): Message
    {
        return $message; // Default: return as-is; subclasses may override
    }

    /**
     * Compress a ContextDelta by trimming large added/modified payloads.
     */
    public function compressDelta(ContextDelta $delta): ContextDelta
    {
        // For now, return the delta unchanged; a smarter version could
        // truncate individual large payloads.
        return $delta;
    }

    private function buildSummaryMessage(array $messages): UserMessage
    {
        $count = count($messages);
        return new UserMessage("[Context summary: {$count} earlier messages compressed to save tokens]");
    }
}
