<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class GitHubTool extends Tool
{
    public function name(): string
    {
        return 'github';
    }

    public function description(): string
    {
        return 'Interact with GitHub via the gh CLI (issues, PRs, repos).';
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
                    'description' => 'gh subcommand and arguments, e.g. "pr list", "issue view 42", "repo view".',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $command = trim($input['command'] ?? '');
        if (empty($command)) {
            return ToolResult::error('GitHub command cannot be empty.');
        }

        // Check that gh is available
        $ghPath = shell_exec('which gh 2>/dev/null');
        if (empty(trim($ghPath ?? ''))) {
            return ToolResult::error('gh CLI not found. Install from https://cli.github.com/.');
        }

        $output = shell_exec("gh {$command} 2>&1");

        return ToolResult::success($output ?? '(no output)');
    }
}
