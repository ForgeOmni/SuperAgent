<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class SendUserFileTool extends Tool
{
    public function name(): string
    {
        return 'SendUserFileTool';
    }

    public function description(): string
    {
        return 'Send files to the user';
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
            'message' => 'SendUserFileTool executed',
            'input' => $input,
            'status' => 'simulated',
        ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}