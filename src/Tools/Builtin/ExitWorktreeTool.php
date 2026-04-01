<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class ExitWorktreeTool extends Tool
{
    public function name(): string
    {
        return 'ExitWorktreeTool';
    }

    public function description(): string
    {
        return 'Exit current git worktree';
    }

    public function category(): string
    {
        return 'git';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform.',
                ],
                'data' => [
                    'type' => 'object',
                    'description' => 'Additional data for the action.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $input): ToolResult
    {
        // Placeholder implementation
        return ToolResult::success([
            'message' => 'ExitWorktreeTool executed',
            'input' => $input,
            'status' => 'simulated',
        ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}