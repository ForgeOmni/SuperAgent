<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class MultiEditTool extends Tool
{
    public function name(): string
    {
        return 'multi_edit';
    }

    public function description(): string
    {
        return 'Make multiple edits to a single file in one operation. Each edit is applied sequentially.';
    }

    public function category(): string
    {
        return 'file';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'File path to edit.',
                ],
                'edits' => [
                    'type' => 'array',
                    'description' => 'Array of edit operations to perform sequentially.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'old_string' => [
                                'type' => 'string',
                                'description' => 'The text to replace.',
                            ],
                            'new_string' => [
                                'type' => 'string',
                                'description' => 'The text to replace it with.',
                            ],
                            'replace_all' => [
                                'type' => 'boolean',
                                'description' => 'Replace all occurrences. Default: false.',
                            ],
                        ],
                        'required' => ['old_string', 'new_string'],
                    ],
                ],
            ],
            'required' => ['path', 'edits'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $path = $input['path'] ?? '';
        $edits = $input['edits'] ?? [];

        if (empty($path)) {
            return ToolResult::error('Path cannot be empty.');
        }

        if (empty($edits)) {
            return ToolResult::error('Edits array cannot be empty.');
        }

        if (!file_exists($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        if (!is_readable($path)) {
            return ToolResult::error("File is not readable: {$path}");
        }

        if (!is_writable($path)) {
            return ToolResult::error("File is not writable: {$path}");
        }

        if (is_dir($path)) {
            return ToolResult::error("Path is a directory, not a file: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        $appliedEdits = 0;
        $failedEdits = [];

        foreach ($edits as $index => $edit) {
            $oldString = $edit['old_string'] ?? '';
            $newString = $edit['new_string'] ?? '';
            $replaceAll = $edit['replace_all'] ?? false;

            if ($oldString === '') {
                $failedEdits[] = "Edit {$index}: old_string cannot be empty";
                continue;
            }

            if ($oldString === $newString) {
                $failedEdits[] = "Edit {$index}: old_string and new_string are the same";
                continue;
            }

            // Check if old_string exists
            if (strpos($content, $oldString) === false) {
                $failedEdits[] = "Edit {$index}: String not found: '{$oldString}'";
                continue;
            }

            // Apply the edit
            if ($replaceAll) {
                $content = str_replace($oldString, $newString, $content);
            } else {
                $pos = strpos($content, $oldString);
                if ($pos !== false) {
                    $content = substr_replace($content, $newString, $pos, strlen($oldString));
                }
            }

            $appliedEdits++;
        }

        if (empty($failedEdits)) {
            // All edits succeeded, write the file
            if (file_put_contents($path, $content) === false) {
                return ToolResult::error("Failed to write to file: {$path}");
            }

            return ToolResult::success("Successfully applied {$appliedEdits} edit(s) to {$path}");
        } else {
            // Some edits failed
            if ($appliedEdits > 0) {
                // Partial success - still write the file
                if (file_put_contents($path, $content) === false) {
                    return ToolResult::error("Failed to write to file: {$path}");
                }

                $message = "Applied {$appliedEdits} edit(s), {" . count($failedEdits) . "} failed:\n";
                $message .= implode("\n", $failedEdits);
                return ToolResult::error($message);
            } else {
                // All edits failed
                $message = "No edits were applied. Errors:\n";
                $message .= implode("\n", $failedEdits);
                return ToolResult::error($message);
            }
        }
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}