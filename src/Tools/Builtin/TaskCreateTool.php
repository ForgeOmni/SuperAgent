<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tasks\TaskManager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TaskCreateTool extends Tool
{
    private ?TaskManager $taskManager;

    public function __construct(?TaskManager $taskManager = null)
    {
        $this->taskManager = $taskManager;
    }

    public function name(): string
    {
        return 'task_create';
    }

    public function description(): string
    {
        return 'Create a new task in the task management system for tracking work items and dependencies.';
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
                'subject' => [
                    'type' => 'string',
                    'description' => 'A brief title for the task',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'What needs to be done',
                ],
                'activeForm' => [
                    'type' => 'string',
                    'description' => 'Present continuous form shown in spinner when in_progress (e.g., "Running tests")',
                ],
                'metadata' => [
                    'type' => 'object',
                    'description' => 'Arbitrary metadata to attach to the task',
                    'additionalProperties' => true,
                ],
                'owner' => [
                    'type' => 'string',
                    'description' => 'Owner/assignee of the task',
                ],
                'blocks' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Task IDs that this task blocks',
                ],
                'blockedBy' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Task IDs that block this task',
                ],
            ],
            'required' => ['subject', 'description'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        // Validate required fields
        if (!isset($input['subject']) || empty($input['subject'])) {
            return ToolResult::error('Task subject is required.');
        }
        if (!isset($input['description']) || empty($input['description'])) {
            return ToolResult::error('Task description is required.');
        }

        $taskManager = $this->taskManager ?? TaskManager::getInstance();

        try {
            $task = $taskManager->createTask([
                'subject' => $input['subject'],
                'description' => $input['description'],
                'activeForm' => $input['activeForm'] ?? null,
                'metadata' => $input['metadata'] ?? [],
                'owner' => $input['owner'] ?? null,
                'blocks' => $input['blocks'] ?? [],
                'blockedBy' => $input['blockedBy'] ?? [],
                'status' => 'pending',
            ]);

            $output = [
                'task' => [
                    'id' => $task->id,
                    'subject' => $task->subject,
                ],
                'message' => "Created task: {$task->id} - {$task->subject}",
            ];

            return ToolResult::success($output);
        } catch (\Exception $e) {
            return ToolResult::error("Failed to create task: " . $e->getMessage());
        }
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}