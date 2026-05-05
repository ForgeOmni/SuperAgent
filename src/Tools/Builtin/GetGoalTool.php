<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Goals\GoalManager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Model tool — read the current goal state.
 *
 * Returns the `Goal::toArray()` shape: id, objective, status,
 * token_budget, tokens_used, remaining_tokens, time_used_seconds,
 * created_at, updated_at. When no goal exists for the thread,
 * returns `{ "goal": null }` rather than an error — the model uses
 * the absence-of-goal as a signal too (e.g. before `create_goal`).
 */
class GetGoalTool extends Tool
{
    public function __construct(
        private GoalManager $goals,
        private string $threadId,
    ) {}

    public function name(): string
    {
        return 'get_goal';
    }

    public function description(): string
    {
        return 'Get the current goal for this thread, including status, budgets, '
            . 'token and elapsed-time usage, and remaining token budget.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => (object) [],
            'required'             => [],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function execute(array $input): ToolResult
    {
        $goal = $this->goals->getActive($this->threadId);
        return ToolResult::success([
            'goal' => $goal?->toArray(),
        ]);
    }
}
