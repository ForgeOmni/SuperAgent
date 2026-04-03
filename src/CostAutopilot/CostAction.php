<?php

declare(strict_types=1);

namespace SuperAgent\CostAutopilot;

/**
 * Actions the autopilot can take when budget thresholds are crossed.
 */
enum CostAction: string
{
    /** Downgrade to a cheaper model tier. */
    case DOWNGRADE_MODEL = 'downgrade_model';

    /** Reduce context window by compacting older messages. */
    case COMPACT_CONTEXT = 'compact_context';

    /** Emit a warning but take no automatic action. */
    case WARN = 'warn';

    /** Hard-stop the agent to prevent further spending. */
    case HALT = 'halt';
}
