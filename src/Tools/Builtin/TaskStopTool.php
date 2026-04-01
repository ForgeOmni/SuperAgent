<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TaskStopTool extends Tool
{
    public function name(): string
    {
        return 'task_stop';
    }

    public function description(): string
    {
        return 'Stop or cancel a running task.';
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
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the task to stop.',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Reason for stopping the task.',
                ],
                'force' => [
                    'type' => 'boolean',
                    'description' => 'Force stop even if task has subtasks. Default: false.',
                ],
            ],
            'required' => ['task_id'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $taskId = $input['task_id'] ?? null;
        $reason = $input['reason'] ?? 'User requested';
        $force = $input['force'] ?? false;

        if ($taskId === null) {
            return ToolResult::error('Task ID is required.');
        }

        $task = TaskCreateTool::getTask($taskId);
        
        if ($task === null) {
            return ToolResult::error("Task with ID {$taskId} not found.");
        }

        // Check if task is already stopped
        if (in_array($task['status'], ['completed', 'cancelled'])) {
            return ToolResult::error("Task {$taskId} is already {$task['status']}.");
        }

        // Check for active subtasks
        if (!empty($task['subtasks']) && !$force) {
            $activeSubtasks = [];
            foreach ($task['subtasks'] as $subtaskId) {
                $subtask = TaskCreateTool::getTask($subtaskId);
                if ($subtask && !in_array($subtask['status'], ['completed', 'cancelled'])) {
                    $activeSubtasks[] = $subtaskId;
                }
            }
            
            if (!empty($activeSubtasks)) {
                return ToolResult::error(
                    "Task has active subtasks: " . implode(', ', $activeSubtasks) . 
                    ". Use force=true to stop anyway."
                );
            }
        }

        // Stop the task
        $updates = [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancellation_reason' => $reason,
        ];

        if (!TaskCreateTool::updateTask($taskId, $updates)) {
            return ToolResult::error("Failed to stop task {$taskId}");
        }

        // Stop all subtasks if forced
        if ($force && !empty($task['subtasks'])) {
            foreach ($task['subtasks'] as $subtaskId) {
                TaskCreateTool::updateTask($subtaskId, [
                    'status' => 'cancelled',
                    'cancelled_at' => date('Y-m-d H:i:s'),
                    'cancellation_reason' => 'Parent task stopped',
                ]);
            }
        }

        return ToolResult::success([
            'message' => 'Task stopped successfully',
            'task_id' => $taskId,
            'reason' => $reason,
            'subtasks_stopped' => $force ? count($task['subtasks']) : 0,
        ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}