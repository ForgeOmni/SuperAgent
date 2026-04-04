<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Alias/entry point used by ToolLoader for the 'edit_file' key.
 * Delegates to FileEditTool.
 */
class EditFileTool extends FileEditTool
{
    public function name(): string
    {
        return 'edit_file';
    }
}
