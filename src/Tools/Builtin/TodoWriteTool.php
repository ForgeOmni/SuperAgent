<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TodoWriteTool extends Tool
{
    private static array $todos = [];

    public function name(): string
    {
        return 'todo_write';
    }

    public function description(): string
    {
        return 'Create and manage a structured task list. Helps track progress and organize complex tasks.';
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
                'todos' => [
                    'type' => 'array',
                    'description' => 'The updated todo list.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => 'The task description (imperative form).',
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pending', 'in_progress', 'completed'],
                                'description' => 'Task status.',
                            ],
                            'activeForm' => [
                                'type' => 'string',
                                'description' => 'Present continuous form of the task.',
                            ],
                        ],
                        'required' => ['content', 'status', 'activeForm'],
                    ],
                ],
            ],
            'required' => ['todos'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $todos = $input['todos'] ?? [];

        if (!is_array($todos)) {
            return ToolResult::error('Todos must be an array.');
        }

        // Validate todos
        foreach ($todos as $index => $todo) {
            if (!isset($todo['content']) || empty($todo['content'])) {
                return ToolResult::error("Todo at index {$index} is missing content.");
            }

            if (!isset($todo['status']) || !in_array($todo['status'], ['pending', 'in_progress', 'completed'])) {
                return ToolResult::error("Todo at index {$index} has invalid status.");
            }

            if (!isset($todo['activeForm']) || empty($todo['activeForm'])) {
                return ToolResult::error("Todo at index {$index} is missing activeForm.");
            }
        }

        // Count status changes
        $oldTodos = self::$todos;
        $changes = [
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
            'completed' => 0,
            'in_progress' => 0,
        ];

        // Analyze changes
        $newContents = array_map(fn($t) => $t['content'], $todos);
        $oldContents = array_map(fn($t) => $t['content'], $oldTodos);

        $changes['added'] = count(array_diff($newContents, $oldContents));
        $changes['removed'] = count(array_diff($oldContents, $newContents));

        foreach ($todos as $todo) {
            if ($todo['status'] === 'completed') {
                $changes['completed']++;
            } elseif ($todo['status'] === 'in_progress') {
                $changes['in_progress']++;
            }
        }

        // Update the static todo list
        self::$todos = $todos;

        // Generate summary
        $summary = $this->generateSummary($todos, $changes);

        return ToolResult::success($summary);
    }

    private function generateSummary(array $todos, array $changes): string
    {
        $lines = ["Todo list updated successfully."];
        
        if ($changes['added'] > 0) {
            $lines[] = "- Added {$changes['added']} new task(s)";
        }
        if ($changes['removed'] > 0) {
            $lines[] = "- Removed {$changes['removed']} task(s)";
        }
        if ($changes['completed'] > 0) {
            $lines[] = "- {$changes['completed']} task(s) completed";
        }
        if ($changes['in_progress'] > 0) {
            $lines[] = "- {$changes['in_progress']} task(s) in progress";
        }

        $lines[] = "\nCurrent todos:";
        foreach ($todos as $index => $todo) {
            $status = $this->formatStatus($todo['status']);
            $lines[] = sprintf("%d. [%s] %s", $index + 1, $status, $todo['content']);
        }

        $totalTasks = count($todos);
        $completedTasks = count(array_filter($todos, fn($t) => $t['status'] === 'completed'));
        $progress = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

        $lines[] = "\nProgress: {$completedTasks}/{$totalTasks} tasks ({$progress}%)";

        return implode("\n", $lines);
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'pending' => '⏳',
            'in_progress' => '🔄',
            'completed' => '✅',
            default => '❓',
        };
    }

    /**
     * Get current todos (for testing or inspection).
     */
    public static function getTodos(): array
    {
        return self::$todos;
    }

    /**
     * Clear todos (for testing).
     */
    public static function clearTodos(): void
    {
        self::$todos = [];
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}