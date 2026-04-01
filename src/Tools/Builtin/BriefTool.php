<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class BriefTool extends Tool
{
    private static string $outputMode = 'normal';
    private static array $modeStack = [];

    public function name(): string
    {
        return 'brief';
    }

    public function description(): string
    {
        return 'Control output verbosity. Set to "brief" for concise output, "verbose" for detailed output, or "normal" for default.';
    }

    public function category(): string
    {
        return 'control';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['brief', 'normal', 'verbose', 'push', 'pop'],
                    'description' => 'Output mode: brief, normal, verbose, push (save current), or pop (restore previous).',
                ],
            ],
            'required' => ['mode'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $mode = $input['mode'] ?? 'normal';

        switch ($mode) {
            case 'push':
                // Save current mode to stack
                self::$modeStack[] = self::$outputMode;
                return ToolResult::success("Saved current output mode: " . self::$outputMode);

            case 'pop':
                // Restore previous mode from stack
                if (empty(self::$modeStack)) {
                    return ToolResult::error('No saved output mode to restore.');
                }
                $previousMode = array_pop(self::$modeStack);
                self::$outputMode = $previousMode;
                return ToolResult::success("Restored output mode to: {$previousMode}");

            case 'brief':
            case 'normal':
            case 'verbose':
                $previousMode = self::$outputMode;
                self::$outputMode = $mode;
                
                if ($previousMode === $mode) {
                    return ToolResult::success("Output mode is already: {$mode}");
                }
                
                return ToolResult::success("Output mode changed from {$previousMode} to {$mode}");

            default:
                return ToolResult::error("Invalid mode: {$mode}. Use brief, normal, verbose, push, or pop.");
        }
    }

    /**
     * Get current output mode.
     */
    public static function getMode(): string
    {
        return self::$outputMode;
    }

    /**
     * Check if in brief mode.
     */
    public static function isBrief(): bool
    {
        return self::$outputMode === 'brief';
    }

    /**
     * Check if in verbose mode.
     */
    public static function isVerbose(): bool
    {
        return self::$outputMode === 'verbose';
    }

    /**
     * Reset to default mode.
     */
    public static function reset(): void
    {
        self::$outputMode = 'normal';
        self::$modeStack = [];
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}