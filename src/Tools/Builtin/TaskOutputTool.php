<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class TaskOutputTool extends Tool
{
    public function name(): string
    {
        return 'task_output';
    }

    public function description(): string
    {
        return 'Set or append output data to a task.';
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
                    'description' => 'The ID of the task.',
                ],
                'output' => [
                    'type' => ['string', 'object', 'array'],
                    'description' => 'Output data to set or append.',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['set', 'append'],
                    'description' => 'Mode: set (replace) or append. Default: set.',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'json', 'markdown'],
                    'description' => 'Output format. Default: text.',
                ],
            ],
            'required' => ['task_id', 'output'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $taskId = $input['task_id'] ?? null;
        $output = $input['output'] ?? '';
        $mode = $input['mode'] ?? 'set';
        $format = $input['format'] ?? 'text';

        if ($taskId === null) {
            return ToolResult::error('Task ID is required.');
        }

        $task = TaskCreateTool::getTask($taskId);
        
        if ($task === null) {
            return ToolResult::error("Task with ID {$taskId} not found.");
        }

        // Prepare output
        $formattedOutput = $this->formatOutput($output, $format);

        // Update task output
        if ($mode === 'append' && $task['output'] !== null) {
            if (is_array($task['output'])) {
                $newOutput = array_merge($task['output'], is_array($formattedOutput) ? $formattedOutput : [$formattedOutput]);
            } else {
                $newOutput = $task['output'] . "\n" . $formattedOutput;
            }
        } else {
            $newOutput = $formattedOutput;
        }

        $updates = [
            'output' => $newOutput,
            'output_format' => $format,
            'output_updated_at' => date('Y-m-d H:i:s'),
        ];

        if (!TaskCreateTool::updateTask($taskId, $updates)) {
            return ToolResult::error("Failed to update task output for task {$taskId}");
        }

        return ToolResult::success([
            'message' => 'Task output updated',
            'task_id' => $taskId,
            'mode' => $mode,
            'format' => $format,
        ]);
    }

    private function formatOutput($output, string $format)
    {
        switch ($format) {
            case 'json':
                if (is_string($output)) {
                    $decoded = json_decode($output, true);
                    return $decoded !== null ? $decoded : $output;
                }
                return $output;
                
            case 'markdown':
                if (is_array($output)) {
                    return $this->arrayToMarkdown($output);
                }
                return $output;
                
            case 'text':
            default:
                if (is_array($output)) {
                    return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
                return $output;
        }
    }

    private function arrayToMarkdown(array $data, int $level = 0): string
    {
        $markdown = '';
        $indent = str_repeat('  ', $level);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $markdown .= "{$indent}- **{$key}**:\n";
                $markdown .= $this->arrayToMarkdown($value, $level + 1);
            } else {
                $markdown .= "{$indent}- **{$key}**: {$value}\n";
            }
        }
        
        return $markdown;
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}