<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class MCPTool extends Tool
{
    public function name(): string
    {
        return 'MCPTool';
    }

    public function description(): string
    {
        return 'Interact with Model Context Protocol servers';
    }

    public function category(): string
    {
        return 'mcp';
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
            'message' => 'MCPTool executed',
            'input' => $input,
            'status' => 'simulated',
        ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}