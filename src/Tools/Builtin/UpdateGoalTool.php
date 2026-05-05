<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Goals\GoalManager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Model tool — update the existing goal.
 *
 * **The only valid status the model may set is `complete`.** Codex's
 * contract here is intentionally narrow: pause, resume, and budget
 * adjustments come from the user / system, not from the model. If
 * the model wants to "give up" mid-objective, it MUST call this with
 * `complete` only when the goal is actually achieved — quitting
 * because the budget is nearly exhausted is explicitly disallowed.
 *
 * The tool result echoes the final usage so the model can report it
 * to the user (the codex prompt tells the model to do so).
 */
class UpdateGoalTool extends Tool
{
    public function __construct(
        private GoalManager $goals,
        private string $threadId,
    ) {}

    public function name(): string
    {
        return 'update_goal';
    }

    public function description(): string
    {
        return "Update the existing goal. Use this tool only to mark the goal achieved. "
            . "Set status to `complete` only when the objective has actually been "
            . "achieved and no required work remains. Do not mark a goal complete "
            . "merely because its budget is nearly exhausted or because you are "
            . "stopping work. You cannot use this tool to pause, resume, or "
            . "budget-limit a goal; those status changes are controlled by the "
            . "user or system.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['complete'],
                    'description' => 'Required. Set to `complete` only when the objective is achieved and no required work remains.',
                ],
            ],
            'required'             => ['status'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function execute(array $input): ToolResult
    {
        $status = (string) ($input['status'] ?? '');
        if ($status !== 'complete') {
            return ToolResult::error(
                "update_goal accepts only status=`complete`. "
                . "Pause / resume / budget changes flow from the user or system."
            );
        }
        $goal = $this->goals->getActive($this->threadId);
        if ($goal === null) {
            return ToolResult::error('No active goal on this thread.');
        }
        $updated = $this->goals->markComplete($goal->id);
        if ($updated === null) {
            return ToolResult::error('Failed to mark goal complete.');
        }
        return ToolResult::success([
            'updated'   => true,
            'goal'      => $updated->toArray(),
            // The model is told to "report the final consumed token
            // budget to the user after update_goal succeeds" — surface
            // it explicitly so the model has it ready.
            'final_tokens_used'      => $updated->tokensUsed,
            'final_elapsed_seconds'  => $updated->elapsedSeconds(),
        ]);
    }
}
