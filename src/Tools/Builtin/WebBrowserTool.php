<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class WebBrowserTool extends Tool
{
    public function name(): string
    {
        return 'WebBrowserTool';
    }

    public function description(): string
    {
        return 'Control a web browser for automation';
    }

    public function category(): string
    {
        return 'browser';
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
            'message' => 'WebBrowserTool executed',
            'input' => $input,
            'status' => 'simulated',
        ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}