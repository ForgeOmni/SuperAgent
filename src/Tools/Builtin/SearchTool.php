<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class SearchTool extends Tool
{
    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'Advanced code search across files using regex or text patterns.';
    }

    public function category(): string
    {
        return 'search';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Search pattern (regex or literal text).',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory or file path to search in. Defaults to current directory.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'File glob filter, e.g. "*.php".',
                ],
                'case_insensitive' => [
                    'type' => 'boolean',
                    'description' => 'Whether to search case-insensitively.',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $pattern = $input['pattern'] ?? '';
        if (empty($pattern)) {
            return ToolResult::error('Pattern cannot be empty.');
        }

        $path = $input['path'] ?? '.';
        $glob = $input['glob'] ?? '*';
        $flags = ($input['case_insensitive'] ?? false) ? 'i' : '';

        $cmd = sprintf(
            'grep -rn%s %s --include=%s %s 2>&1 | head -200',
            $flags ? 'i' : '',
            escapeshellarg($pattern),
            escapeshellarg($glob),
            escapeshellarg($path)
        );

        $output = shell_exec($cmd);

        if ($output === null || trim($output) === '') {
            return ToolResult::success('No matches found.');
        }

        return ToolResult::success($output);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
