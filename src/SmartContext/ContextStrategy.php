<?php

declare(strict_types=1);

namespace SuperAgent\SmartContext;

/**
 * Strategy for allocating tokens between thinking and context.
 */
enum ContextStrategy: string
{
    /**
     * Complex reasoning task: maximize thinking budget, aggressively compact context.
     * Ratio: ~60% thinking, ~40% context.
     */
    case DEEP_THINKING = 'deep_thinking';

    /**
     * Balanced allocation for typical tasks.
     * Ratio: ~40% thinking, ~60% context.
     */
    case BALANCED = 'balanced';

    /**
     * Simple or context-heavy task: minimize thinking, preserve full conversation.
     * Ratio: ~15% thinking, ~85% context.
     */
    case BROAD_CONTEXT = 'broad_context';

    /**
     * Get the thinking budget ratio (0.0 - 1.0).
     */
    public function thinkingRatio(): float
    {
        return match ($this) {
            self::DEEP_THINKING => 0.60,
            self::BALANCED => 0.40,
            self::BROAD_CONTEXT => 0.15,
        };
    }

    /**
     * Get the context ratio (0.0 - 1.0).
     */
    public function contextRatio(): float
    {
        return 1.0 - $this->thinkingRatio();
    }

    /**
     * Get the compaction aggressiveness (lower = more aggressive).
     */
    public function compactionKeepRecent(): int
    {
        return match ($this) {
            self::DEEP_THINKING => 4,   // Keep only last 4 messages
            self::BALANCED => 8,        // Keep last 8
            self::BROAD_CONTEXT => 16,  // Keep last 16
        };
    }
}
