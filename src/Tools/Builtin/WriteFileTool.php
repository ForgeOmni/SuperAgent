<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class WriteFileTool extends Tool
{
    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Write content to a file. Creates the file (and parent directories) if it does not exist, or overwrites if it does.';
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
                    'description' => 'File path to write to.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content to write to the file.',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $path = $input['path'] ?? '';
        $content = $input['content'] ?? '';

        if (empty($path)) {
            return ToolResult::error('Path cannot be empty.');
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! mkdir($dir, 0755, true)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            return ToolResult::error("Failed to write to file: {$path}");
        }

        $lines = substr_count($content, "\n") + 1;

        return ToolResult::success("Wrote {$bytes} bytes ({$lines} lines) to {$path}");
    }
}
