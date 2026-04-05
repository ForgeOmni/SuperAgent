<?php

declare(strict_types=1);

namespace SuperAgent\Tools;

/**
 * Bidirectional tool name resolver between SuperAgent and Claude Code formats.
 *
 * Claude Code uses PascalCase names (Read, Write, Edit, Bash, Grep, Glob, Agent, etc.)
 * SuperAgent uses snake_case names (read_file, write_file, edit_file, bash, grep, glob, agent, etc.)
 *
 * This resolver ensures 100% compatibility:
 * - Agent definitions loaded from .claude/agents/ can use CC tool names in allowed_tools/disallowed_tools
 * - SuperAgent's permission system resolves CC names to internal names
 * - The mapping is bidirectional: SA→CC and CC→SA
 */
class ToolNameResolver
{
    /**
     * Claude Code name → SuperAgent name mapping.
     * CC names are the authoritative external format.
     */
    private const CC_TO_SA = [
        // Core file tools
        'Read'              => 'read_file',
        'Write'             => 'write_file',
        'Edit'              => 'edit_file',
        'MultiEdit'         => 'multi_edit',
        'Glob'              => 'glob',
        'Grep'              => 'grep',
        'Bash'              => 'bash',
        'NotebookEdit'      => 'notebook_edit',

        // Agent & orchestration
        'Agent'             => 'agent',
        'Task'              => 'agent',  // Legacy CC name
        'SendMessage'       => 'send_message',
        'TeamCreate'        => 'TeamCreateTool',
        'TeamDelete'        => 'TeamDeleteTool',

        // Task management
        'TaskCreate'        => 'task_create',
        'TaskGet'           => 'task_get',
        'TaskList'          => 'task_list',
        'TaskUpdate'        => 'task_update',
        'TaskOutput'        => 'task_output',
        'TaskStop'          => 'task_stop',
        'TodoWrite'         => 'todo_write',

        // Plan mode
        'EnterPlanMode'     => 'enter_plan_mode',
        'ExitPlanMode'      => 'exit_plan_mode',

        // Worktree
        'EnterWorktree'     => 'EnterWorktreeTool',
        'ExitWorktree'      => 'ExitWorktreeTool',

        // Web & network
        'WebSearch'         => 'web_search',
        'WebFetch'          => 'web_fetch',
        'HttpRequest'       => 'http_request',

        // MCP
        'MCPTool'           => 'mcp',
        'ListMcpResourcesTool' => 'list_mcp_resources',
        'ReadMcpResourceTool'  => 'ReadMcpResourceTool',
        'McpAuthTool'       => 'McpAuthTool',

        // Utility
        'ToolSearch'        => 'ToolSearch',
        'Skill'             => 'skill',
        'Config'            => 'config',
        'AskUserQuestion'   => 'ask_user',
        'Brief'             => 'brief',
        'Sleep'             => 'sleep',
        'REPL'              => 'repl',
        'LSP'               => 'LSPTool',
        'PowerShell'        => 'PowerShellTool',

        // Triggers
        'RemoteTrigger'     => 'RemoteTriggerTool',
        'CronCreate'        => 'ScheduleCronTool',
        'CronDelete'        => 'ScheduleCronTool',
        'CronList'          => 'ScheduleCronTool',
        'SendUserMessage'   => 'SendUserFileTool',
    ];

    /** SuperAgent name → Claude Code name (reverse mapping, built lazily) */
    private static ?array $saToCC = null;

    /**
     * Resolve a tool name to its SuperAgent internal name.
     * Accepts both CC format (Read) and SA format (read_file).
     * Returns the input unchanged if no mapping exists.
     */
    public static function toSuperAgent(string $name): string
    {
        return self::CC_TO_SA[$name] ?? $name;
    }

    /**
     * Resolve a tool name to its Claude Code external name.
     * Accepts both SA format (read_file) and CC format (Read).
     * Returns the input unchanged if no mapping exists.
     */
    public static function toClaudeCode(string $name): string
    {
        if (self::$saToCC === null) {
            self::$saToCC = array_flip(self::CC_TO_SA);
        }

        return self::$saToCC[$name] ?? $name;
    }

    /**
     * Resolve an array of tool names to SuperAgent format.
     * Used when loading .claude/agents/ definitions.
     *
     * @param string[] $names Tool names (may be mixed CC/SA format)
     * @return string[] All resolved to SA format
     */
    public static function resolveAll(array $names): array
    {
        return array_map([self::class, 'toSuperAgent'], $names);
    }

    /**
     * Check if a name is a Claude Code format name.
     */
    public static function isClaudeCodeName(string $name): bool
    {
        return isset(self::CC_TO_SA[$name]);
    }

    /**
     * Get the full mapping table.
     */
    public static function getMapping(): array
    {
        return self::CC_TO_SA;
    }
}
