<?php

namespace SuperAgent\Tools;

// Core tools
use SuperAgent\Tools\Builtin\AskUserQuestionTool;
use SuperAgent\Tools\Builtin\BashTool;
use SuperAgent\Tools\Builtin\BriefTool;
use SuperAgent\Tools\Builtin\ConfigTool;
use SuperAgent\Tools\Builtin\FileEditTool;
use SuperAgent\Tools\Builtin\AgentGrepTool;
use SuperAgent\Tools\Builtin\GlobTool;
use SuperAgent\Tools\Builtin\GrepTool;
use SuperAgent\Tools\Builtin\HttpRequestTool;
use SuperAgent\Tools\Builtin\MultiEditTool;
use SuperAgent\Tools\Builtin\NotebookEditTool;
use SuperAgent\Tools\Builtin\ReadFileTool;
use SuperAgent\Tools\Builtin\REPLTool;
use SuperAgent\Tools\Builtin\SleepTool;
use SuperAgent\Tools\Builtin\WebFetchTool;
use SuperAgent\Tools\Builtin\WebSearchTool;
use SuperAgent\Tools\Builtin\WriteFileTool;

// Task management tools
use SuperAgent\Tools\Builtin\TaskCreateTool;
use SuperAgent\Tools\Builtin\TaskGetTool;
use SuperAgent\Tools\Builtin\TaskListTool;
use SuperAgent\Tools\Builtin\TaskUpdateTool;
use SuperAgent\Tools\Builtin\TaskStopTool;
use SuperAgent\Tools\Builtin\TaskOutputTool;
use SuperAgent\Tools\Builtin\TodoWriteTool;

// Planning tools
use SuperAgent\Tools\Builtin\EnterPlanModeTool;
use SuperAgent\Tools\Builtin\ExitPlanModeTool;
use SuperAgent\Tools\Builtin\VerifyPlanExecutionTool;

// Automation tools
use SuperAgent\Tools\Builtin\WorkflowTool;
use SuperAgent\Tools\Builtin\SkillTool;
use SuperAgent\Tools\Builtin\DiscoverSkillsTool;

// Code and snippet tools
use SuperAgent\Tools\Builtin\SnipTool;
use SuperAgent\Tools\Builtin\LSPTool;

// Monitoring and debugging tools
use SuperAgent\Tools\Builtin\MonitorTool;
use SuperAgent\Tools\Builtin\TerminalCaptureTool;
use SuperAgent\Tools\Builtin\CtxInspectTool;
use SuperAgent\Tools\Builtin\SpillReadTool;
use SuperAgent\Tools\Builtin\SessionQueryTool;

// Agent and team tools
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Tools\Builtin\SendMessageTool;

// MCP tools
use SuperAgent\Tools\Builtin\ListMcpResourcesTool;

// Other tools
use SuperAgent\Tools\Builtin\ToolSearchTool;

class BuiltinToolRegistry
{
    /**
     * Return the canonical name → FQCN mapping for every builtin tool.
     *
     * This is the single source of truth consumed by ToolLoader for lazy
     * registration. No instantiation happens here.
     *
     * Every entry here MUST be a real implementation. Placeholder tools that
     * returned `status: simulated` were removed in v1.1.11 — a registered
     * tool that lies about executing is worse than an absent tool
     * (deepseek-harness discipline: registered capability must be real).
     *
     * @return array<string, class-string<Tool>>
     */
    public static function classMap(): array
    {
        return [
            // Execution
            'bash'                  => BashTool::class,
            'repl'                  => REPLTool::class,
            'sleep'                 => SleepTool::class,

            // File
            'read_file'             => ReadFileTool::class,
            'write_file'            => WriteFileTool::class,
            'file_edit'             => FileEditTool::class,
            'multi_edit'            => MultiEditTool::class,
            'notebook_edit'         => NotebookEditTool::class,

            // Search
            'glob'                  => GlobTool::class,
            'grep'                  => GrepTool::class,
            // jcode-style grep: enclosing-symbol injection + per-session
            // seen-chunk truncation. Sibling of `grep`, not a replacement.
            'agent_grep'            => AgentGrepTool::class,
            'tool_search'           => ToolSearchTool::class,

            // Network
            'http_request'          => HttpRequestTool::class,
            'web_search'            => WebSearchTool::class,
            'web_fetch'             => WebFetchTool::class,

            // Task management
            'task_create'           => TaskCreateTool::class,
            'task_get'              => TaskGetTool::class,
            'task_list'             => TaskListTool::class,
            'task_update'           => TaskUpdateTool::class,
            'task_stop'             => TaskStopTool::class,
            'task_output'           => TaskOutputTool::class,
            'todo_write'            => TodoWriteTool::class,

            // Planning
            'enter_plan_mode'       => EnterPlanModeTool::class,
            'exit_plan_mode'        => ExitPlanModeTool::class,
            'verify_plan_execution' => VerifyPlanExecutionTool::class,

            // Automation
            'workflow'              => WorkflowTool::class,
            'skill'                 => SkillTool::class,
            'discover_skills'       => DiscoverSkillsTool::class,

            // Code / snippet
            'snip'                  => SnipTool::class,
            'lsp'                   => LSPTool::class,

            // Monitoring / debug
            'monitor'               => MonitorTool::class,
            'terminal_capture'      => TerminalCaptureTool::class,
            'ctx_inspect'           => CtxInspectTool::class,
            'spill_read'            => SpillReadTool::class,
            'session_query'         => SessionQueryTool::class,

            // Agent & team
            'agent'                 => AgentTool::class,
            'send_message'          => SendMessageTool::class,

            // MCP
            'list_mcp_resources'    => ListMcpResourcesTool::class,

            // System / control
            'config'                => ConfigTool::class,
            'brief'                 => BriefTool::class,

            // Interaction
            'ask_user'              => AskUserQuestionTool::class,
        ];
    }

    /**
     * Get all built-in tools.
     *
     * @return array<string, Tool>
     */
    public static function all(): array
    {
        return [
            // Execution tools — always available
            'bash' => new BashTool(),
            'repl' => new REPLTool(),
            'sleep' => new SleepTool(),

            // File tools — always available
            'read_file' => new ReadFileTool(),
            'write_file' => new WriteFileTool(),
            'file_edit' => new FileEditTool(),
            'multi_edit' => new MultiEditTool(),
            'notebook_edit' => new NotebookEditTool(),

            // Search tools — always available
            'glob' => new GlobTool(),
            'grep' => new GrepTool(),
            'tool_search' => new ToolSearchTool(),

            // Network tools — always available
            'http_request' => new HttpRequestTool(),
            'web_search' => new WebSearchTool(),
            'web_fetch' => new WebFetchTool(),

            // Task management tools — always available
            'task_create' => new TaskCreateTool(),
            'task_get' => new TaskGetTool(),
            'task_list' => new TaskListTool(),
            'task_update' => new TaskUpdateTool(),
            'task_stop' => new TaskStopTool(),
            'task_output' => new TaskOutputTool(),
            'todo_write' => new TodoWriteTool(),

            // Planning tools — always available
            'enter_plan_mode' => new EnterPlanModeTool(),
            'exit_plan_mode' => new ExitPlanModeTool(),
            'verify_plan_execution' => new VerifyPlanExecutionTool(),

            // Automation tools — always available
            'workflow' => new WorkflowTool(),
            'skill' => new SkillTool(),
            'discover_skills' => new DiscoverSkillsTool(),

            // Code and snippet tools — always available
            'snip' => new SnipTool(),
            'lsp' => new LSPTool(),

            // Monitoring and debugging tools — always available
            'monitor' => new MonitorTool(),
            'terminal_capture' => new TerminalCaptureTool(),
            'ctx_inspect' => new CtxInspectTool(),
            'spill_read' => new SpillReadTool(),
            'session_query' => new SessionQueryTool(),

            // Agent and team tools — always available
            'agent' => new AgentTool(),
            'send_message' => new SendMessageTool(),

            // MCP tools — always available
            'list_mcp_resources' => new ListMcpResourcesTool(),

            // System/Control tools — always available
            'config' => new ConfigTool(),
            'brief' => new BriefTool(),

            // Interaction tools — always available
            'ask_user' => new AskUserQuestionTool(),
        ];
    }

    /**
     * Get tools by category.
     *
     * @param string $category
     * @return array<string, Tool>
     */
    public static function byCategory(string $category): array
    {
        $tools = static::all();
        $filtered = [];

        foreach ($tools as $name => $tool) {
            if ($tool->category() === $category) {
                $filtered[$name] = $tool;
            }
        }

        return $filtered;
    }

    /**
     * Get a specific tool by name.
     *
     * @param string $name
     * @return Tool|null
     */
    public static function get(string $name): ?Tool
    {
        $tools = static::all();

        return $tools[$name] ?? null;
    }

    /**
     * Get read-only tools (safe for untrusted execution).
     *
     * @return array<string, Tool>
     */
    public static function readOnly(): array
    {
        $tools = static::all();
        $filtered = [];

        foreach ($tools as $name => $tool) {
            if ($tool->isReadOnly()) {
                $filtered[$name] = $tool;
            }
        }

        return $filtered;
    }

    /**
     * Get available categories.
     *
     * @return array<string>
     */
    public static function categories(): array
    {
        $tools = static::all();
        $categories = [];

        foreach ($tools as $tool) {
            $category = $tool->category();
            if (!in_array($category, $categories)) {
                $categories[] = $category;
            }
        }

        sort($categories);

        return $categories;
    }

    /**
     * Get tool count.
     *
     * @return int
     */
    public static function count(): int
    {
        return count(static::all());
    }
}
