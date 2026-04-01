<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

/**
 * Coordinator mode ported from Claude Code.
 *
 * Dual-mode architecture:
 *  - Coordinator: Pure synthesis/delegation — only Agent, SendMessage, TaskStop tools
 *  - Worker: Pure execution — all work tools (Bash, Read, Edit, etc.)
 *
 * The coordinator never executes tasks directly. Instead it:
 *  1. Spawns independent worker agents via Agent tool
 *  2. Receives results as task notifications
 *  3. Synthesizes findings into implementation specs
 *  4. Delegates all work to workers
 */
class CoordinatorMode
{
    /** Tools the coordinator is allowed to use */
    public const COORDINATOR_TOOLS = [
        'Agent',
        'SendMessage',
        'TaskStop',
    ];

    /** Internal tools filtered from worker tool pools */
    private const INTERNAL_WORKER_TOOLS = [
        'SendMessage',
        'TeamCreate',
        'TeamDelete',
    ];

    private bool $isCoordinator = false;
    private ?string $sessionMode = null;

    public function __construct(
        bool $coordinatorMode = false,
    ) {
        $this->isCoordinator = $coordinatorMode || $this->checkEnvironment();
    }

    /**
     * Check if coordinator mode is active.
     */
    public function isCoordinatorMode(): bool
    {
        return $this->isCoordinator;
    }

    /**
     * Enable coordinator mode.
     */
    public function enable(): void
    {
        $this->isCoordinator = true;
        $this->sessionMode = 'coordinator';
    }

    /**
     * Disable coordinator mode (switch to normal).
     */
    public function disable(): void
    {
        $this->isCoordinator = false;
        $this->sessionMode = 'normal';
    }

    /**
     * Match session mode for session restoration.
     * If the stored session was coordinator mode but current is not (or vice versa),
     * switch to match.
     *
     * @return string|null Warning message if mode was switched, null otherwise
     */
    public function matchSessionMode(string $storedMode): ?string
    {
        if ($storedMode === 'coordinator' && !$this->isCoordinator) {
            $this->enable();
            return 'Entered coordinator mode to match resumed session.';
        }

        if ($storedMode === 'normal' && $this->isCoordinator) {
            $this->disable();
            return 'Exited coordinator mode to match resumed session.';
        }

        return null;
    }

    /**
     * Get the current session mode string.
     */
    public function getSessionMode(): string
    {
        return $this->isCoordinator ? 'coordinator' : 'normal';
    }

    /**
     * Filter tools for coordinator — only allow orchestration tools.
     *
     * @param array $tools Array of tool objects/definitions
     * @return array Filtered tools
     */
    public function filterCoordinatorTools(array $tools): array
    {
        if (!$this->isCoordinator) {
            return $tools;
        }

        return array_filter($tools, function ($tool) {
            $name = is_object($tool) && method_exists($tool, 'name')
                ? $tool->name()
                : ($tool['name'] ?? '');
            return in_array($name, self::COORDINATOR_TOOLS, true);
        });
    }

    /**
     * Filter tools for workers — remove internal orchestration tools.
     *
     * @param array $tools Array of tool objects/definitions
     * @return array Filtered tools for worker use
     */
    public function filterWorkerTools(array $tools): array
    {
        return array_filter($tools, function ($tool) {
            $name = is_object($tool) && method_exists($tool, 'name')
                ? $tool->name()
                : ($tool['name'] ?? '');
            return !in_array($name, self::INTERNAL_WORKER_TOOLS, true);
        });
    }

    /**
     * Get worker tool names for injection into coordinator context.
     *
     * @param array $tools Full tool list
     * @return string[] Worker-available tool names
     */
    public function getWorkerToolNames(array $tools): array
    {
        $workerTools = $this->filterWorkerTools($tools);

        return array_map(function ($tool) {
            return is_object($tool) && method_exists($tool, 'name')
                ? $tool->name()
                : ($tool['name'] ?? '');
        }, array_values($workerTools));
    }

    /**
     * Get the coordinator system prompt.
     */
    public function getSystemPrompt(array $workerToolNames = [], ?string $scratchpadDir = null): string
    {
        $toolList = implode(', ', $workerToolNames);
        $scratchpadNote = $scratchpadDir
            ? "\n\nScratchpad directory: {$scratchpadDir}\nWorkers can read and write here without permission prompts. Use this for durable cross-worker knowledge."
            : '';

        return <<<PROMPT
You are an orchestrator. You do NOT execute tasks directly.
Your role: direct workers, synthesize results, communicate with the user.

Worker results and system notifications are internal signals — they are NOT conversation partners.

## Your tools

1. **Agent tool** — Spawn a new worker with a self-contained task.
2. **SendMessage tool** — Continue an existing worker (preserves its context).
3. **TaskStop tool** — Kill a worker going in the wrong direction.

Workers spawned via the Agent tool have access to these tools: {$toolList}{$scratchpadNote}

## How task results arrive

When a worker finishes, you receive an XML notification:
```
<task-notification>
  <task-id>agent-xxx</task-id>
  <status>completed|failed|killed</status>
  <summary>Human-readable outcome</summary>
  <result>Agent's final response</result>
</task-notification>
```

## Workflow

| Phase          | Owner           | Purpose                                    |
|----------------|-----------------|--------------------------------------------|
| Research       | Workers (parallel) | Investigate codebase independently       |
| Synthesis      | **You**         | Read findings, understand problem, craft specs |
| Implementation | Workers         | Make changes per your spec                 |
| Verification   | Fresh workers   | Test changes independently                 |

## Delegation rules

- **Never delegate understanding.** Read research results yourself, then write specific implementation specs.
- **Self-contained prompts.** Workers can't see your conversation. Include all context: file paths, line numbers, types, what to change, and why.
- **Parallel research.** Launch multiple read-only workers in ONE message.
- **Sequential writes.** Only one write worker per file set at a time.
- **Fresh eyes for verification.** Always use a fresh worker for testing.

## Continue vs Spawn decision

| Situation | Action | Why |
|-----------|--------|-----|
| Research explored the files needing edit | **Continue** (SendMessage) | Worker has files in context |
| Research was broad, implementation is narrow | **Spawn fresh** | Avoid dragging noise |
| Correcting failure or extending work | **Continue** | Worker knows what it tried |
| Verifying code from another worker | **Spawn fresh** | Independent perspective |
| Wrong approach entirely | **Spawn fresh** | Clean slate |

## Anti-patterns

- "Based on your findings, fix the bug" ← delegates understanding
- Predicting outcomes before notifications arrive
- Using one worker to check on another worker
- Spawning workers without specific file paths and line numbers
PROMPT;
    }

    /**
     * Build the coordinator user context message.
     * Injected as first user message to inform the coordinator what workers can do.
     */
    public function getUserContext(array $workerToolNames = [], array $mcpToolNames = [], ?string $scratchpadDir = null): string
    {
        $toolList = implode(', ', $workerToolNames);
        $mcpNote = !empty($mcpToolNames)
            ? "\n\nWorkers also have access to MCP tools: " . implode(', ', $mcpToolNames)
            : '';
        $scratchpadNote = $scratchpadDir
            ? "\n\nScratchpad directory: {$scratchpadDir}\nWorkers can read and write here. Use this for durable cross-worker knowledge."
            : '';

        return "Workers spawned via the Agent tool have access to these tools: {$toolList}{$mcpNote}{$scratchpadNote}";
    }

    private function checkEnvironment(): bool
    {
        $envValue = $_ENV['CLAUDE_CODE_COORDINATOR_MODE'] ?? getenv('CLAUDE_CODE_COORDINATOR_MODE');
        return $envValue === '1' || $envValue === 'true';
    }
}
