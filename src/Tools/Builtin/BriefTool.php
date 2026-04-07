<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class BriefTool extends Tool
{
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
                $stack = $this->state()->get($this->name(), 'modeStack', []);
                $stack[] = $this->state()->get($this->name(), 'outputMode', 'normal');
                $this->state()->set($this->name(), 'modeStack', $stack);
                return ToolResult::success("Saved current output mode: " . $this->state()->get($this->name(), 'outputMode', 'normal'));

            case 'pop':
                // Restore previous mode from stack
                $stack = $this->state()->get($this->name(), 'modeStack', []);
                if (empty($stack)) {
                    return ToolResult::error('No saved output mode to restore.');
                }
                $previousMode = array_pop($stack);
                $this->state()->set($this->name(), 'modeStack', $stack);
                $this->state()->set($this->name(), 'outputMode', $previousMode);
                return ToolResult::success("Restored output mode to: {$previousMode}");

            case 'brief':
            case 'normal':
            case 'verbose':
                $previousMode = $this->state()->get($this->name(), 'outputMode', 'normal');
                $this->state()->set($this->name(), 'outputMode', $mode);

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
    public function getMode(): string
    {
        return $this->state()->get($this->name(), 'outputMode', 'normal');
    }

    /**
     * Check if in brief mode.
     */
    public function isBrief(): bool
    {
        return $this->state()->get($this->name(), 'outputMode', 'normal') === 'brief';
    }

    /**
     * Check if in verbose mode.
     */
    public function isVerbose(): bool
    {
        return $this->state()->get($this->name(), 'outputMode', 'normal') === 'verbose';
    }

    /**
     * Reset to default mode.
     */
    public function reset(): void
    {
        $this->state()->clearTool($this->name());
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}