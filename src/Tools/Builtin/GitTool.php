<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class GitTool extends Tool
{
    public function name(): string
    {
        return 'git';
    }

    public function description(): string
    {
        return 'Execute Git commands for version control operations.';
    }

    public function category(): string
    {
        return 'vcs';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Git subcommand and arguments, e.g. "status", "log --oneline -10", "diff HEAD".',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $command = trim($input['command'] ?? '');
        if (empty($command)) {
            return ToolResult::error('Git command cannot be empty.');
        }

        // Basic safety: block destructive push --force to main/master
        if (preg_match('/push\s+.*--force.*\s+(main|master)/', $command)) {
            return ToolResult::error('Force-pushing to main/master is blocked for safety.');
        }

        $output = shell_exec("git {$command} 2>&1");

        return ToolResult::success($output ?? '(no output)');
    }
}
