<?php

namespace SuperAgent\Contracts;

use SuperAgent\Tools\ToolResult;

interface ToolInterface
{
    /**
     * Unique tool name (snake_case).
     */
    public function name(): string;

    /**
     * Human-readable description for the LLM.
     */
    public function description(): string;

    /**
     * JSON Schema describing the input parameters.
     */
    public function inputSchema(): array;

    /**
     * Execute the tool with validated input and return a result.
     */
    public function execute(array $input): ToolResult;

    /**
     * Whether this tool only reads state (no side effects).
     */
    public function isReadOnly(): bool;
}
