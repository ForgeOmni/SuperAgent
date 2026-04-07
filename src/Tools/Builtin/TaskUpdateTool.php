<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tasks\TaskManager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TaskUpdateTool extends Tool
{
    private ?TaskManager $taskManager;

    public function __construct(?TaskManager $taskManager = null)
    {
        $this->taskManager = $taskManager;
    }

    public function name(): string
    {
        return 'task_update';
    }

    public function description(): string
    {
        return 'Update a task\'s status, subject, description, or other properties including dependency relationships.';
    }

    public function category(): string
    {
        return 'task';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => [
                    'type' => 'string',
                    'description' => 'The ID of the task to update',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'New subject for the task',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'New description for the task',
                ],
                'activeForm' => [
                    'type' => 'string',
                    'description' => 'Present continuous form shown in spinner when in_progress (e.g., "Running tests")',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'in_progress', 'completed', 'failed', 'killed', 'deleted'],
                    'description' => 'New status for the task (use "deleted" to delete the task)',
                ],
                'addBlocks' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Task IDs that this task blocks',
                ],
                'addBlockedBy' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Task IDs that block this task',
                ],
                'owner' => [
                    'type' => 'string',
                    'description' => 'New owner for the task',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Metadata keys to merge into the task. Set a key to null to delete it.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['taskId'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $taskManager = $this->taskManager ?? TaskManager::getInstance();
        $taskId = $input['taskId'] ?? null;

        if ($taskId === null) {
            return ToolResult::error('Task ID is required.');
        }

        // Check if task exists
        $existingTask = $taskManager->getTask($taskId);
        if (!$existingTask) {
            return ToolResult::error("Task with ID {$taskId} not found.");
        }

        // Special case: handle deletion
        if (isset($input['status']) && $input['status'] === 'deleted') {
            $deleted = $taskManager->deleteTask($taskId);
            if ($deleted) {
                return ToolResult::success([
                    'success' => true,
                    'taskId' => $taskId,
                    'message' => "Task {$taskId} deleted successfully",
                ]);
            } else {
                return ToolResult::error("Failed to delete task {$taskId}");
            }
        }

        // Build updates array
        $updates = [];
        $updatedFields = [];

        if (isset($input['subject'])) {
            $updates['subject'] = $input['subject'];
            $updatedFields[] = 'subject';
        }

        if (isset($input['description'])) {
            $updates['description'] = $input['description'];
            $updatedFields[] = 'description';
        }

        if (isset($input['activeForm'])) {
            $updates['activeForm'] = $input['activeForm'];
            $updatedFields[] = 'activeForm';
        }

        if (isset($input['status'])) {
            $updates['status'] = $input['status'];
            $updatedFields[] = 'status';
        }

        if (isset($input['owner'])) {
            $updates['owner'] = $input['owner'];
            $updatedFields[] = 'owner';
        }

        if (isset($input['metadata'])) {
            $updates['metadata'] = $input['metadata'];
            $updatedFields[] = 'metadata';
        }

        if (isset($input['addBlocks'])) {
            $updates['addBlocks'] = $input['addBlocks'];
            $updatedFields[] = 'blocks';
        }

        if (isset($input['addBlockedBy'])) {
            $updates['addBlockedBy'] = $input['addBlockedBy'];
            $updatedFields[] = 'blockedBy';
        }

        if (empty($updates)) {
            return ToolResult::success([
                'success' => true,
                'taskId' => $taskId,
                'updatedFields' => [],
                'message' => 'No changes made',
            ]);
        }

        // Apply updates
        $oldStatus = $existingTask->status->value;
        $success = $taskManager->updateTask($taskId, $updates);

        if (!$success) {
            return ToolResult::error("Failed to update task {$taskId}");
        }

        $updatedTask = $taskManager->getTask($taskId);
        
        $result = [
            'success' => true,
            'taskId' => $taskId,
            'updatedFields' => $updatedFields,
            'message' => 'Task updated successfully',
        ];

        // Add status change info if status was updated
        if (isset($input['status'])) {
            $result['statusChange'] = [
                'from' => $oldStatus,
                'to' => $input['status'],
            ];
        }

        return ToolResult::success($result);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}