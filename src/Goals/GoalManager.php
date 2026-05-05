<?php

declare(strict_types=1);

namespace SuperAgent\Goals;

use SuperAgent\Goals\Contracts\GoalStore;
use SuperAgent\Security\UntrustedInput;

/**
 * Orchestrates goal lifecycle: creation, transitions, token
 * accounting, and the two prompt-injection paths the runtime needs
 * (continuation when the agent goes idle, budget-limit when the
 * token cap fires).
 *
 * Templates live as plain Markdown alongside this class so they can
 * be diffed / versioned / overridden by hosts that want a different
 * voice. The class supplies sensible defaults if the templates are
 * missing — useful when SuperAgent is consumed as a Composer dep and
 * the resources/ tree wasn't copied.
 *
 * The manager is intentionally thread-aware (each call takes a
 * threadId) but stateless beyond what the GoalStore holds. Callers
 * pass the threadId on every method; we don't carry session state.
 */
final class GoalManager
{
    private const TEMPLATE_DIR = __DIR__ . '/../../resources/prompts/goals';

    public function __construct(
        private GoalStore $store,
    ) {}

    /**
     * Create a fresh goal on the thread. Throws
     * `GoalAlreadyExistsException` if one already exists — the
     * caller should call `getActive()` first and decide whether to
     * complete it or keep working.
     */
    public function create(string $threadId, string $objective, ?int $tokenBudget = null): Goal
    {
        $objective = trim($objective);
        if ($objective === '') {
            throw new \InvalidArgumentException('Goal objective must be non-empty.');
        }
        if ($tokenBudget !== null && $tokenBudget <= 0) {
            throw new \InvalidArgumentException('Token budget must be a positive integer when set.');
        }
        return $this->store->create($threadId, $objective, $tokenBudget);
    }

    public function getActive(string $threadId): ?Goal
    {
        return $this->store->findActive($threadId);
    }

    /**
     * Mark the goal complete. Per codex, this is the ONLY status
     * change the model is allowed to drive — pause/resume/budget
     * changes flow from the user/system, not from update_goal.
     */
    public function markComplete(string $goalId): ?Goal
    {
        return $this->store->transition($goalId, GoalStatus::Complete);
    }

    public function pause(string $goalId): ?Goal
    {
        return $this->store->transition($goalId, GoalStatus::Paused);
    }

    public function resume(string $goalId): ?Goal
    {
        return $this->store->transition($goalId, GoalStatus::Active);
    }

    /**
     * Stamp turn token usage onto the goal. When the new total
     * crosses the configured budget, transitions to `budget_limited`
     * and returns the updated snapshot — callers should observe the
     * status change and inject `renderBudgetLimitPrompt()` into the
     * next turn's input.
     */
    public function recordUsage(string $goalId, int $turnTokens): ?Goal
    {
        $goal = $this->store->findById($goalId);
        if ($goal === null) return null;
        $newTotal = $goal->tokensUsed + max(0, $turnTokens);
        $goal = $this->store->recordTokens($goalId, $newTotal);
        if ($goal === null) return null;

        if ($goal->status === GoalStatus::Active
            && $goal->tokenBudget !== null
            && $newTotal >= $goal->tokenBudget
        ) {
            $goal = $this->store->transition($goalId, GoalStatus::BudgetLimited) ?? $goal;
        }
        return $goal;
    }

    /**
     * Render the continuation prompt that should be injected when
     * the agent goes idle but the goal is still active. The user
     * objective is wrapped in `<untrusted_objective>` to neutralise
     * prompt-injection from the goal text itself.
     */
    public function renderContinuationPrompt(Goal $goal): string
    {
        return $this->renderTemplate('continuation.md', $this->templateContextFor($goal));
    }

    /**
     * Render the budget-limit prompt. Used once when the goal flips
     * to `budget_limited` — model is asked to wrap up rather than
     * start new substantive work.
     */
    public function renderBudgetLimitPrompt(Goal $goal): string
    {
        return $this->renderTemplate('budget_limit.md', $this->templateContextFor($goal));
    }

    /**
     * Build the placeholder bag both templates share.
     *
     * @return array<string, string|int|null>
     */
    private function templateContextFor(Goal $goal): array
    {
        return [
            'objective'         => UntrustedInput::tag($goal->objective, 'objective'),
            'time_used_seconds' => $goal->elapsedSeconds(),
            'tokens_used'       => $goal->tokensUsed,
            'token_budget'      => $goal->tokenBudget !== null ? (string) $goal->tokenBudget : 'unbounded',
            'remaining_tokens'  => $goal->remainingBudget() !== null ? (string) $goal->remainingBudget() : 'unbounded',
        ];
    }

    /**
     * Mustache-lite: replace `{{ key }}` (with surrounding whitespace
     * tolerated) using the supplied context. Falls back to a built-in
     * default when the template file is absent — handy when the
     * resources tree wasn't shipped alongside the Composer install.
     *
     * @param array<string, string|int|null> $context
     */
    private function renderTemplate(string $name, array $context): string
    {
        $path = self::TEMPLATE_DIR . '/' . $name;
        $template = is_readable($path)
            ? (string) file_get_contents($path)
            : self::fallbackTemplate($name);
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            static fn ($m) => (string) ($context[$m[1]] ?? ''),
            $template,
        ) ?? $template;
    }

    private static function fallbackTemplate(string $name): string
    {
        // Minimal but correct fallbacks. The full versions live in
        // resources/prompts/goals/ — these are last-ditch defaults so
        // a mis-packaged install still produces a non-empty string.
        return match ($name) {
            'continuation.md' =>
                "Continue working toward the active thread goal.\n\n"
                . "{{objective}}\n\n"
                . "Budget: tokens used {{tokens_used}} of {{token_budget}}; remaining {{remaining_tokens}}; "
                . "elapsed {{time_used_seconds}}s.\n\n"
                . "Pick the next concrete action. Mark complete only after auditing that every "
                . "requirement is met — don't accept proxy signals (passing tests, plausible "
                . "answers) on their own. If achieved, call update_goal status=complete.",
            'budget_limit.md' =>
                "The active thread goal has reached its token budget.\n\n"
                . "{{objective}}\n\n"
                . "Budget: {{tokens_used}} tokens used of {{token_budget}}; elapsed {{time_used_seconds}}s.\n\n"
                . "Wrap up: summarise progress, identify remaining work, leave a clear next step. "
                . "Don't start new substantive work and don't mark complete unless the goal is actually achieved.",
            default => '',
        };
    }
}
