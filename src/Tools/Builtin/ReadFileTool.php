<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class ReadFileTool extends Tool
{
    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read the contents of a file. Returns file content with line numbers. Supports offset and limit for large files.';
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
                    'description' => 'Absolute or relative file path to read.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Line number to start reading from (0-based). Default: 0.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of lines to read. Default: 2000.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $path = $input['path'] ?? '';
        $offset = max(0, $input['offset'] ?? 0);
        $limit = min(max(1, $input['limit'] ?? 2000), 10000);

        if (empty($path)) {
            return ToolResult::error('Path cannot be empty.');
        }

        if (! file_exists($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        if (! is_readable($path)) {
            return ToolResult::error("File is not readable: {$path}");
        }

        if (is_dir($path)) {
            return ToolResult::error("Path is a directory, not a file: {$path}");
        }

        $lines = file($path);
        if ($lines === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        $totalLines = count($lines);
        $slice = array_slice($lines, $offset, $limit);

        $output = '';
        foreach ($slice as $i => $line) {
            $lineNum = $offset + $i + 1;
            $output .= sprintf("%d\t%s", $lineNum, $line);
        }

        if ($totalLines > $offset + $limit) {
            $remaining = $totalLines - $offset - $limit;
            $output .= "\n... ({$remaining} more lines)";
        }

        return ToolResult::success($output ?: '(empty file)');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
