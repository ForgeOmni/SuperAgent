<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class SleepTool extends Tool
{
    public function name(): string
    {
        return 'sleep';
    }

    public function description(): string
    {
        return 'Pause execution for a specified number of seconds. Useful for rate limiting or waiting for async operations.';
    }

    public function category(): string
    {
        return 'execution';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'seconds' => [
                    'type' => 'number',
                    'description' => 'Number of seconds to sleep (can be fractional, e.g., 0.5 for 500ms).',
                ],
            ],
            'required' => ['seconds'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $seconds = $input['seconds'] ?? 0;

        if (!is_numeric($seconds)) {
            return ToolResult::error('Seconds must be a number.');
        }

        if ($seconds < 0) {
            return ToolResult::error('Seconds cannot be negative.');
        }

        if ($seconds > 60) {
            return ToolResult::error('Sleep duration cannot exceed 60 seconds.');
        }

        $microseconds = (int) ($seconds * 1000000);
        
        $startTime = microtime(true);
        usleep($microseconds);
        $actualDuration = microtime(true) - $startTime;

        return ToolResult::success(sprintf(
            "Slept for %.3f seconds (requested: %.3f seconds)",
            $actualDuration,
            $seconds
        ));
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}