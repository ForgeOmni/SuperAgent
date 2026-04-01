<?php

namespace SuperAgent\Tools;

// Core tools
use SuperAgent\Tools\Builtin\AskUserQuestionTool;
use SuperAgent\Tools\Builtin\BashTool;
use SuperAgent\Tools\Builtin\BriefTool;
use SuperAgent\Tools\Builtin\ConfigTool;
use SuperAgent\Tools\Builtin\FileEditTool;
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
use SuperAgent\Tools\Builtin\ScheduleCronTool;
use SuperAgent\Tools\Builtin\RemoteTriggerTool;

// Code and snippet tools
use SuperAgent\Tools\Builtin\SnipTool;
use SuperAgent\Tools\Builtin\LSPTool;

// Monitoring and debugging tools
use SuperAgent\Tools\Builtin\MonitorTool;
use SuperAgent\Tools\Builtin\TerminalCaptureTool;
use SuperAgent\Tools\Builtin\CtxInspectTool;
use SuperAgent\Tools\Builtin\OverflowTestTool;

// Agent and team tools
use SuperAgent\Tools\Builtin\AgentTool;
use SuperAgent\Tools\Builtin\SendMessageTool;
use SuperAgent\Tools\Builtin\TeamCreateTool;
use SuperAgent\Tools\Builtin\TeamDeleteTool;
use SuperAgent\Tools\Builtin\ListPeersTool;

// MCP tools
use SuperAgent\Tools\Builtin\MCPTool;
use SuperAgent\Tools\Builtin\ListMcpResourcesTool;
use SuperAgent\Tools\Builtin\ReadMcpResourceTool;
use SuperAgent\Tools\Builtin\McpAuthTool;

// Git and review tools
use SuperAgent\Tools\Builtin\EnterWorktreeTool;
use SuperAgent\Tools\Builtin\ExitWorktreeTool;
use SuperAgent\Tools\Builtin\SubscribePRTool;
use SuperAgent\Tools\Builtin\SuggestBackgroundPRTool;
use SuperAgent\Tools\Builtin\ReviewArtifactTool;

// Other tools
use SuperAgent\Tools\Builtin\PowerShellTool;
use SuperAgent\Tools\Builtin\SendUserFileTool;
use SuperAgent\Tools\Builtin\SyntheticOutputTool;
use SuperAgent\Tools\Builtin\ToolSearchTool;
use SuperAgent\Tools\Builtin\TungstenTool;
use SuperAgent\Tools\Builtin\WebBrowserTool;
use SuperAgent\Tools\Builtin\PushNotificationTool;

class BuiltinToolRegistry
{
    /**
     * Get all built-in tools, respecting experimental feature flags.
     *
     * Core tools are always available. Experimental tools require their
     * corresponding feature flag to be enabled in config.
     *
     * @return array<string, Tool>
     */
    public static function all(): array
    {
        $tools = [
            // Execution tools (4) — always available
            'bash' => new BashTool(),
            'repl' => new REPLTool(),
            'sleep' => new SleepTool(),
            'powershell' => new PowerShellTool(),

            // File tools (6) — always available
            'read_file' => new ReadFileTool(),
            'write_file' => new WriteFileTool(),
            'file_edit' => new FileEditTool(),
            'multi_edit' => new MultiEditTool(),
            'notebook_edit' => new NotebookEditTool(),
            'send_user_file' => new SendUserFileTool(),

            // Search tools (3) — always available
            'glob' => new GlobTool(),
            'grep' => new GrepTool(),
            'tool_search' => new ToolSearchTool(),

            // Network tools (3) — always available
            'http_request' => new HttpRequestTool(),
            'web_search' => new WebSearchTool(),
            'web_fetch' => new WebFetchTool(),

            // Task management tools (7) — always available
            'task_create' => new TaskCreateTool(),
            'task_get' => new TaskGetTool(),
            'task_list' => new TaskListTool(),
            'task_update' => new TaskUpdateTool(),
            'task_stop' => new TaskStopTool(),
            'task_output' => new TaskOutputTool(),
            'todo_write' => new TodoWriteTool(),

            // Planning tools (3) — always available
            'enter_plan_mode' => new EnterPlanModeTool(),
            'exit_plan_mode' => new ExitPlanModeTool(),
            'verify_plan_execution' => new VerifyPlanExecutionTool(),

            // Core automation tools (4) — always available
            'workflow' => new WorkflowTool(),
            'skill' => new SkillTool(),
            'discover_skills' => new DiscoverSkillsTool(),
            'web_browser' => new WebBrowserTool(),

            // Code and snippet tools (2) — always available
            'snip' => new SnipTool(),
            'lsp' => new LSPTool(),

            // Monitoring and debugging tools (4) — always available
            'monitor' => new MonitorTool(),
            'terminal_capture' => new TerminalCaptureTool(),
            'ctx_inspect' => new CtxInspectTool(),
            'overflow_test' => new OverflowTestTool(),

            // Agent and team tools (3) — always available
            'agent' => new AgentTool(),
            'send_message' => new SendMessageTool(),
            'list_peers' => new ListPeersTool(),

            // MCP tools (4) — always available
            'mcp' => new MCPTool(),
            'list_mcp_resources' => new ListMcpResourcesTool(),
            'read_mcp_resource' => new ReadMcpResourceTool(),
            'mcp_auth' => new McpAuthTool(),

            // Git and review tools (5) — always available
            'enter_worktree' => new EnterWorktreeTool(),
            'exit_worktree' => new ExitWorktreeTool(),
            'subscribe_pr' => new SubscribePRTool(),
            'suggest_background_pr' => new SuggestBackgroundPRTool(),
            'review_artifact' => new ReviewArtifactTool(),

            // System/Control tools (2) — always available
            'config' => new ConfigTool(),
            'brief' => new BriefTool(),

            // Interaction and notification tools (3) — always available
            'ask_user' => new AskUserQuestionTool(),
            'push_notification' => new PushNotificationTool(),
            'synthetic_output' => new SyntheticOutputTool(),

            // Special tools (1) — always available
            'tungsten' => new TungstenTool(),
        ];

        // --- Experimental tools (gated by feature flags) ---

        $exp = \SuperAgent\Config\ExperimentalFeatures::class;

        // Agent triggers: local cron scheduling
        if ($exp::enabled('agent_triggers')) {
            $tools['schedule_cron'] = new ScheduleCronTool();
        }

        // Agent triggers remote: API-based remote agent tasks
        if ($exp::enabled('agent_triggers_remote')) {
            $tools['remote_trigger'] = new RemoteTriggerTool();
        }

        // Team memory: team create/delete tools
        if ($exp::enabled('team_memory')) {
            $tools['team_create'] = new TeamCreateTool();
            $tools['team_delete'] = new TeamDeleteTool();
        }

        return $tools;
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