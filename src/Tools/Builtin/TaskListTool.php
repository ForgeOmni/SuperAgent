<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TaskListTool extends Tool
{
    public function name(): string
    {
        return 'task_list';
    }

    public function description(): string
    {
        return 'List all tasks with optional filtering by status, priority, or tags.';
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
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'in_progress', 'completed', 'cancelled'],
                    'description' => 'Filter by task status.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'critical'],
                    'description' => 'Filter by priority.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Filter by tags (tasks must have all specified tags).',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by parent task ID (show only subtasks of this task).',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ['created_at', 'updated_at', 'priority', 'status'],
                    'description' => 'Sort field. Default: created_at.',
                ],
                'sort_order' => [
                    'type' => 'string',
                    'enum' => ['asc', 'desc'],
                    'description' => 'Sort order. Default: desc.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of tasks to return. Default: 50.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $statusFilter = $input['status'] ?? null;
        $priorityFilter = $input['priority'] ?? null;
        $tagsFilter = $input['tags'] ?? [];
        $parentIdFilter = $input['parent_id'] ?? null;
        $sortBy = $input['sort_by'] ?? 'created_at';
        $sortOrder = $input['sort_order'] ?? 'desc';
        $limit = min(max(1, $input['limit'] ?? 50), 500);

        $tasks = TaskCreateTool::getAllTasks();
        
        // Apply filters
        $filteredTasks = [];
        foreach ($tasks as $task) {
            // Status filter
            if ($statusFilter !== null && $task['status'] !== $statusFilter) {
                continue;
            }
            
            // Priority filter
            if ($priorityFilter !== null && $task['priority'] !== $priorityFilter) {
                continue;
            }
            
            // Tags filter
            if (!empty($tagsFilter)) {
                $hasAllTags = true;
                foreach ($tagsFilter as $tag) {
                    if (!in_array($tag, $task['tags'])) {
                        $hasAllTags = false;
                        break;
                    }
                }
                if (!$hasAllTags) {
                    continue;
                }
            }
            
            // Parent ID filter
            if ($parentIdFilter !== null && $task['parent_id'] !== $parentIdFilter) {
                continue;
            }
            
            $filteredTasks[] = $task;
        }
        
        // Sort tasks
        usort($filteredTasks, function ($a, $b) use ($sortBy, $sortOrder) {
            $aValue = $a[$sortBy] ?? '';
            $bValue = $b[$sortBy] ?? '';
            
            // Special handling for priority
            if ($sortBy === 'priority') {
                $priorityOrder = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
                $aValue = $priorityOrder[$aValue] ?? 0;
                $bValue = $priorityOrder[$bValue] ?? 0;
            }
            
            $comparison = $aValue <=> $bValue;
            
            return $sortOrder === 'desc' ? -$comparison : $comparison;
        });
        
        // Apply limit
        $filteredTasks = array_slice($filteredTasks, 0, $limit);
        
        // Format output
        $summary = [
            'total_count' => count($filteredTasks),
            'filters_applied' => array_filter([
                'status' => $statusFilter,
                'priority' => $priorityFilter,
                'tags' => $tagsFilter ?: null,
                'parent_id' => $parentIdFilter,
            ]),
            'tasks' => $filteredTasks,
        ];
        
        return ToolResult::success($summary);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}