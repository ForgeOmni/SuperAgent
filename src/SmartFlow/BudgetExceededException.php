<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

/**
 * Thrown when an {@see Flow::agent()} call would push the flow past its token or
 * USD budget. Mirrors Claude Code's Workflow `budget` hard-ceiling: once the
 * pool is exhausted, further agent() calls throw rather than silently overspend.
 */
class BudgetExceededException extends \RuntimeException
{
}
