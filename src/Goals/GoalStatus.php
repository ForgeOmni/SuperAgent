<?php

declare(strict_types=1);

namespace SuperAgent\Goals;

/**
 * Lifecycle of a thread goal — borrowed wholesale from codex's
 * `ThreadGoalStatus`. Three terminal states (complete, paused,
 * budget_limited) and one running state (active). The model can ONLY
 * transition `active → complete`; pause/resume and budget enforcement
 * are user/system-controlled.
 *
 *   active          — goal is live; the agent should keep working
 *                     toward the objective on each idle turn.
 *   complete        — model called update_goal(complete); usage
 *                     accounting closes out and the auto-continuation
 *                     loop exits.
 *   paused          — user paused via `/goal pause` (or analogous host
 *                     UI). Agent stops auto-continuing on idle, but
 *                     the goal record persists so /goal resume puts
 *                     it back to active.
 *   budget_limited  — the goal exceeded its `token_budget`. The
 *                     budget_limit.md template is injected ONCE; the
 *                     agent is asked to wrap up rather than do new
 *                     substantive work. A budget-limited goal can
 *                     still be marked complete by the model if it
 *                     achieves the objective during the wrap-up.
 */
enum GoalStatus: string
{
    case Active         = 'active';
    case Complete       = 'complete';
    case Paused         = 'paused';
    case BudgetLimited  = 'budget_limited';

    /**
     * Whether the auto-continuation loop should keep firing for this
     * status. Active and budget_limited both keep the loop alive
     * (budget_limited fires once more so the model can wrap up).
     */
    public function isLive(): bool
    {
        return $this === self::Active || $this === self::BudgetLimited;
    }

    public function isTerminal(): bool
    {
        return $this === self::Complete;
    }
}
