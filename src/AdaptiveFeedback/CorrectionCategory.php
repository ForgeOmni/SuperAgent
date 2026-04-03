<?php

declare(strict_types=1);

namespace SuperAgent\AdaptiveFeedback;

/**
 * Categories of user corrections/denials that the system can learn from.
 */
enum CorrectionCategory: string
{
    /** User denied a tool execution (permission denied). */
    case TOOL_DENIED = 'tool_denied';

    /** User rejected agent output (e.g., reverted edits, said "no"). */
    case OUTPUT_REJECTED = 'output_rejected';

    /** User corrected agent behavior via explicit feedback. */
    case BEHAVIOR_CORRECTION = 'behavior_correction';

    /** User undid a file edit the agent made. */
    case EDIT_REVERTED = 'edit_reverted';

    /** User flagged content as unwanted (e.g., unnecessary comments). */
    case CONTENT_UNWANTED = 'content_unwanted';

    /**
     * Whether this category warrants a guardrails rule (vs. a memory entry).
     */
    public function shouldGenerateRule(): bool
    {
        return match ($this) {
            self::TOOL_DENIED, self::EDIT_REVERTED => true,
            default => false,
        };
    }

    /**
     * Whether this category warrants a memory entry.
     */
    public function shouldGenerateMemory(): bool
    {
        return match ($this) {
            self::OUTPUT_REJECTED, self::BEHAVIOR_CORRECTION, self::CONTENT_UNWANTED => true,
            default => false,
        };
    }
}
