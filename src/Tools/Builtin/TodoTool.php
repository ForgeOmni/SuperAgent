<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Manage an in-memory todo list for the current session.
 * Alias used by ToolLoader for the 'todo' key.
 */
class TodoTool extends TodoWriteTool
{
    public function name(): string
    {
        return 'todo';
    }
}
