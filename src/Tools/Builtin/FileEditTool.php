<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class FileEditTool extends Tool
{
    public function name(): string
    {
        return 'file_edit';
    }

    public function description(): string
    {
        return 'Performs precise string replacements in files. Finds exact occurrences of old_string and replaces with new_string. Use replace_all to replace all occurrences.';
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
                    'description' => 'Absolute or relative file path to edit.',
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => 'The exact string to find and replace. Must match exactly including whitespace.',
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => 'The string to replace old_string with.',
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences of old_string. Default: false (replace first occurrence only).',
                ],
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $path = $input['path'] ?? '';
        $oldString = $input['old_string'] ?? '';
        $newString = $input['new_string'] ?? '';
        $replaceAll = $input['replace_all'] ?? false;

        if (empty($path)) {
            return ToolResult::error('Path cannot be empty.');
        }

        if ($oldString === '') {
            return ToolResult::error('old_string cannot be empty.');
        }

        if ($oldString === $newString) {
            return ToolResult::error('old_string and new_string must be different.');
        }

        if (! file_exists($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        if (! is_readable($path)) {
            return ToolResult::error("File is not readable: {$path}");
        }

        if (! is_writable($path)) {
            return ToolResult::error("File is not writable: {$path}");
        }

        if (is_dir($path)) {
            return ToolResult::error("Path is a directory, not a file: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        // Check if old_string exists in the file
        $occurrences = substr_count($content, $oldString);
        if ($occurrences === 0) {
            return ToolResult::error("String not found in file: '{$oldString}'");
        }

        // Perform replacement
        if ($replaceAll) {
            $newContent = str_replace($oldString, $newString, $content);
            $replacedCount = $occurrences;
        } else {
            // Replace only the first occurrence
            $pos = strpos($content, $oldString);
            if ($pos === false) {
                return ToolResult::error("String not found in file: '{$oldString}'");
            }
            $newContent = substr_replace($content, $newString, $pos, strlen($oldString));
            $replacedCount = 1;
        }

        // Write the modified content back
        if (file_put_contents($path, $newContent) === false) {
            return ToolResult::error("Failed to write to file: {$path}");
        }

        $message = "Successfully replaced {$replacedCount} occurrence(s) in {$path}";
        if ($occurrences > $replacedCount) {
            $remaining = $occurrences - $replacedCount;
            $message .= " ({$remaining} occurrence(s) remaining)";
        }

        return ToolResult::success($message);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}