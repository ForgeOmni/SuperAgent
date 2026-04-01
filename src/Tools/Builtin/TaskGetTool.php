<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tasks\TaskManager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TaskGetTool extends Tool
{
    public function name(): string
    {
        return 'task_get';
    }

    public function description(): string
    {
        return 'Get details of a specific task by its ID, including status, dependencies, and metadata.';
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
                    'description' => 'The ID of the task to retrieve',
                ],
            ],
            'required' => ['taskId'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $taskManager = TaskManager::getInstance();
        $taskId = $input['taskId'] ?? null;

        if ($taskId === null) {
            return ToolResult::error('Task ID is required.');
        }

        $task = $taskManager->getTask($taskId);
        
        if ($task === null) {
            return ToolResult::error("Task with ID {$taskId} not found.");
        }

        // Convert task to array and include output if available
        $taskData = $task->toArray();
        
        // Get task output if exists
        $output = $taskManager->getTaskOutput($taskId);
        if ($output) {
            $taskData['output'] = $output;
        }

        return ToolResult::success([
            'task' => $taskData,
            'message' => "Retrieved task: {$task->subject}",
        ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}