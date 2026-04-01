<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class SyntheticOutputTool extends Tool
{
    public function name(): string
    {
        return 'SyntheticOutputTool';
    }

    public function description(): string
    {
        return 'Generate synthetic output for testing';
    }

    public function category(): string
    {
        return 'output';
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
            'message' => 'SyntheticOutputTool executed',
            'input' => $input,
            'status' => 'simulated',
        ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}