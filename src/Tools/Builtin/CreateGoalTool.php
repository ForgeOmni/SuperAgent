<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Goals\GoalAlreadyExistsException;
use SuperAgent\Goals\GoalManager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Model tool — start a new thread-scoped goal.
 *
 * Mirrors codex's `create_goal` exactly:
 *
 *   - `objective`     required free-form text. Wrapped in
 *                     `<untrusted_objective>` whenever it gets
 *                     rendered into a prompt downstream.
 *   - `token_budget`  optional positive integer. When set, the runtime
 *                     flips the goal to `budget_limited` once usage
 *                     crosses it.
 *
 * The tool fails when an active goal already exists on the thread —
 * that's what forces the model to call `update_goal` (mark complete)
 * before starting a new objective. We mirror codex's contract here.
 */
class CreateGoalTool extends Tool
{
    public function __construct(
        private GoalManager $goals,
        private string $threadId,
    ) {}

    public function name(): string
    {
        return 'create_goal';
    }

    public function description(): string
    {
        return 'Create a goal only when explicitly requested by the user or system/developer instructions; do not infer goals from ordinary tasks. '
            . 'Set token_budget only when an explicit token budget is requested. '
            . 'Fails if a goal exists; use update_goal to mark it complete first.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'objective' => [
                    'type' => 'string',
                    'description' => 'The concrete objective to start pursuing. Required.',
                ],
                'token_budget' => [
                    'type' => 'integer',
                    'description' => 'Optional positive token budget for the new active goal.',
                    'minimum' => 1,
                ],
            ],
            'required' => ['objective'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function execute(array $input): ToolResult
    {
        $objective = (string) ($input['objective'] ?? '');
        $budget    = isset($input['token_budget']) ? (int) $input['token_budget'] : null;

        try {
            $goal = $this->goals->create($this->threadId, $objective, $budget);
        } catch (GoalAlreadyExistsException $e) {
            return ToolResult::error($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::success([
            'created' => true,
            'goal'    => $goal->toArray(),
        ]);
    }
}
