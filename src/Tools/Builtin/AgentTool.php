<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Agent\AgentManager;
use SuperAgent\Permissions\PermissionMode;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\IsolationMode;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\TeamMember;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Tool for spawning and managing sub-agents.
 *
 * Default execution model uses ProcessBackend (true OS-level parallelism).
 * Each sub-agent runs in its own PHP process with its own Guzzle connection,
 * so blocking HTTP I/O and tool execution never block the parent or siblings.
 */
class AgentTool extends Tool
{
    private array $backends = [];
    private ?TeamContext $teamContext = null;
    private LoggerInterface $logger;
    private array $activeTasks = [];
    private array $providerConfig = [];
    private ?AgentManager $agentManager;
    private ?ParallelAgentCoordinator $coordinator;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?AgentManager $agentManager = null,
        ?ParallelAgentCoordinator $coordinator = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->agentManager = $agentManager;
        $this->coordinator = $coordinator;
        $this->initializeBackends();
    }

    /**
     * Inject the parent agent's provider config so that spawned sub-agents
     * can authenticate against the same LLM endpoint.
     */
    public function setProviderConfig(array $config): void
    {
        $this->providerConfig = $config;
    }

    public function name(): string
    {
        return 'agent';
    }

    public function description(): string
    {
        return 'Launch a new agent to handle complex, multi-step tasks autonomously. ' .
               'The agent runs independently and can use tools to complete its task. ' .
               'Parallelism: to run multiple agents concurrently, emit all agent calls as ' .
               'separate tool_use blocks in a single assistant message — the runtime fans ' .
               'them out in parallel and blocks until every child finishes, returning each ' .
               "child's final output. Do NOT set run_in_background=true unless you genuinely " .
               'want fire-and-forget (returns async_launched immediately, no result to read) — ' .
               'that mode is wrong for any workflow that needs to consolidate child outputs. ' .
               'Result status values: "completed" (child finished normally — tools may or ' .
               'may not have been called, files may or may not have been written; inspect ' .
               'the filesWritten field to decide), "completed_empty" (child made ZERO tool ' .
               'calls — model described the task instead of executing; ALWAYS re-dispatch or ' .
               'pick a stronger model), "async_launched" (background mode only). Each result ' .
               'carries filesWritten (list of absolute paths), toolCallsByName (map), and ' .
               'productivityWarning (informational string or null). If the task required ' .
               'reports/CSVs on disk and filesWritten is empty, check the child text first — ' .
               'advisory consults often return results inline — and only re-dispatch when ' .
               'the text also lacks the expected content.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => [
                    'type' => 'string',
                    'description' => 'A short (3-5 word) description of the task',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The task for the agent to perform',
                ],
                'subagent_type' => [
                    'type' => 'string',
                    'description' => 'The type of specialized agent to use for this task',
                    'enum' => ($this->agentManager ?? AgentManager::getInstance())->getNames(),
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Name for the spawned agent. Makes it addressable via SendMessage',
                ],
                'team_name' => [
                    'type' => 'string',
                    'description' => 'Team name for spawning. Uses current team context if omitted',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Optional model override for this agent',
                    'enum' => ['claude-3-opus', 'claude-3-sonnet', 'claude-3-haiku', 'gpt-4', 'gpt-3.5-turbo'],
                ],
                'mode' => [
                    'type' => 'string',
                    'description' => 'Permission mode for spawned agent',
                    'enum' => self::permissionModeEnum(),
                ],
                'isolation' => [
                    'type' => 'string',
                    'description' => 'Isolation mode. "worktree" creates a temporary git worktree',
                    'enum' => array_column(IsolationMode::cases(), 'value'),
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Fire-and-forget mode. Default (false) blocks until the child finishes ' .
                                     "and returns its output — this is what you want when you plan to read " .
                                     "the child's result. Set true ONLY for genuine background tasks whose " .
                                     'output you do not need in this turn (long-running polls, telemetry, etc.). ' .
                                     'To run multiple agents concurrently, leave this false and emit all agent ' .
                                     'calls in a single assistant message — the runtime parallelizes them.',
                ],
                'output_subdir' => [
                    'type' => 'string',
                    'description' => 'Absolute or project-relative directory the child is expected to write its ' .
                                     'deliverables into. When set, the runtime (a) appends a guard block to the ' .
                                     "child's prompt warning against sibling-role sub-directories, consolidator " .
                                     'reserved filenames, and non-whitelisted extensions; and (b) audits the ' .
                                     'directory after the child exits, returning any violations under ' .
                                     "`outputWarnings` in the tool result. Omit to skip both — the legacy " .
                                     'productivity signals (filesWritten / toolCallsByName) keep working either way.',
                ],
            ],
            'required' => ['description', 'prompt'],
        ];
    }

    public function category(): string
    {
        return 'execution';
    }

    public function execute(array $input): ToolResult
    {
        try {
            $description = $input['description'];
            $prompt = $input['prompt'];
            $subagentType = $input['subagent_type'] ?? 'general-purpose';
            $name = $input['name'] ?? $this->generateAgentName($subagentType);
            $teamName = $input['team_name'] ?? $this->teamContext?->getTeamName();
            $model = $input['model'] ?? null;
            $mode = isset($input['mode']) ? self::resolvePermissionMode($input['mode']) : null;
            $isolation = isset($input['isolation']) ? IsolationMode::from($input['isolation']) : IsolationMode::NONE;
            $runInBackground = $input['run_in_background'] ?? false;
            $outputSubdir = isset($input['output_subdir']) && is_string($input['output_subdir']) && $input['output_subdir'] !== ''
                ? $input['output_subdir']
                : null;

            // Prepend a host-injected guard block when the caller opted
            // into output-auditing by setting output_subdir. The block is
            // idempotent via its marker — re-composing the same prompt
            // multiple times (e.g. if the parent model retries with edits)
            // doesn't duplicate the guards.
            if ($outputSubdir !== null) {
                $guard = AgentOutputAuditor::guardBlock($prompt, $name);
                if ($guard !== '') {
                    $prompt = rtrim($prompt) . "\n\n" . $guard;
                }
            }

            $this->logger->info('Spawning agent', [
                'name' => $name,
                'type' => $subagentType,
                'background' => $runInBackground,
                'team' => $teamName,
            ]);

            // Team context setup
            if ($teamName && !$this->teamContext) {
                $this->teamContext = TeamContext::load($teamName);
                if (!$this->teamContext) {
                    $leaderId = 'leader_' . uniqid();
                    $this->teamContext = new TeamContext($teamName, $leaderId);
                    $this->teamContext->save();
                }
            }

            // Always use ProcessBackend for real execution (true parallelism).
            // InProcessBackend (fiber) is only kept for unit tests without proc_open.
            $backend = $this->getProcessBackend();

            if (!$backend->isAvailable()) {
                // Fallback to in-process if proc_open is disabled
                $backend = $this->getBackend(BackendType::IN_PROCESS);
                $this->logger->warning('proc_open unavailable, falling back to in-process backend');
            }

            // Resolve agent definition for system prompt / allowed tools
            $definition = ($this->agentManager ?? AgentManager::getInstance())->get($subagentType);

            $config = new AgentSpawnConfig(
                name: $name,
                prompt: $prompt,
                teamName: $teamName,
                model: $model,
                systemPrompt: $definition?->systemPrompt(),
                permissionMode: $mode,
                backend: BackendType::PROCESS,
                isolation: $isolation,
                runInBackground: $runInBackground,
                allowedTools: $definition?->allowedTools(),
                deniedTools: $definition?->disallowedTools(),
                planModeRequired: $mode === PermissionMode::PLAN,
                readOnly: $definition?->readOnly() ?? false,
                providerConfig: $this->providerConfig,
            );

            $result = $backend->spawn($config);

            if (!$result->success) {
                return ToolResult::failure(
                    'Failed to spawn agent: ' . ($result->error ?? 'Unknown error')
                );
            }

            // Register with team
            if ($this->teamContext) {
                $member = new TeamMember(
                    agentId: $result->agentId,
                    name: $name,
                    backend: BackendType::PROCESS,
                    taskId: $result->taskId,
                    pid: $result->pid,
                    status: AgentStatus::RUNNING,
                );
                $this->teamContext->addMember($member);
                $this->teamContext->save();
            }

            $this->activeTasks[$result->agentId] = [
                'task_id' => $result->taskId,
                'name' => $name,
                'backend_instance' => $backend,
                'started_at' => new \DateTimeImmutable(),
                // Stashed for CJK-vs-EN routing of productivityWarning text.
                // Localising to the caller's language avoids Chinese runs
                // getting an English warning that leaks zh/en mixing into
                // the orchestrator's consolidation output.
                'prompt' => $prompt,
                // Output-dir contract, opt-in. Non-null enables the
                // post-exit audit (extension whitelist, reserved-filename
                // check, sibling-role detection) via AgentOutputAuditor.
                'output_subdir' => $outputSubdir,
                // Productivity accumulators — populated by applyProgressEvents()
                // as the child's tool_use events stream in. Used by
                // waitForProcessCompletion() to detect the "completed but
                // unproductive" failure mode (child returns success:true
                // without calling any tools or writing any files) and surface
                // it to the orchestrator via productivityWarning.
                'tool_counts' => [],     // name => count
                'files_written' => [],   // list of absolute paths
            ];

            // Synchronous (foreground) mode: wait for the process to finish
            if (!$runInBackground) {
                return $this->waitForProcessCompletion(
                    $backend,
                    $result->agentId,
                    $name,
                    $prompt,
                    $subagentType,
                );
            }

            // Background mode: return immediately
            $output = [
                'status' => 'async_launched',
                'agentId' => $result->agentId,
                'task_id' => $result->taskId,
                'description' => $description,
                'prompt' => $prompt,
                'name' => $name,
                'backend' => 'process',
                'message' => "Agent '{$name}' started in background. You'll be notified when it completes.",
            ];

            if ($result->worktreePath) {
                $output['worktree'] = $result->worktreePath;
            }
            if ($result->pid) {
                $output['pid'] = $result->pid;
            }

            return ToolResult::success($output);

        } catch (\Exception $e) {
            $this->logger->error('Failed to spawn agent', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::failure('Failed to spawn agent: ' . $e->getMessage());
        }
    }

    /**
     * Wait for a process-based agent to complete and return its result.
     */
    private function waitForProcessCompletion(
        ProcessBackend|BackendInterface $backend,
        string $agentId,
        string $name,
        string $prompt,
        string $agentType,
    ): ToolResult {
        $startTime = microtime(true);
        $maxWaitTime = 300; // 5 minutes

        // Register with coordinator so the process monitor can display progress
        $coordinatorInstance = $this->coordinator ?? ParallelAgentCoordinator::getInstance();
        $tracker = $coordinatorInstance->registerAgent($agentId, $name);

        // If it's a ProcessBackend, use its poll/waitAll mechanism
        if ($backend instanceof ProcessBackend) {
            while (microtime(true) - $startTime < $maxWaitTime) {
                $statuses = $backend->poll();

                // Feed progress events from child stderr into the tracker
                $this->applyProgressEvents($backend, $agentId, $tracker);

                $status = $statuses[$agentId] ?? null;
                if ($status === AgentStatus::COMPLETED || $status === AgentStatus::FAILED) {
                    $tracker->setStatus($status === AgentStatus::COMPLETED ? 'completed' : 'failed');
                    break;
                }

                usleep(50_000); // 50ms
            }

            $result = $backend->getResult($agentId);

            if (!$result) {
                return ToolResult::failure('Agent execution timed out');
            }

            if (!($result['success'] ?? false)) {
                return ToolResult::failure(
                    'Agent failed: ' . ($result['error'] ?? 'Unknown error')
                );
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $usage = $result['usage'] ?? [];

            $content = [];
            $text = $result['text'] ?? '';
            if (!empty($text)) {
                $content[] = ['type' => 'text', 'text' => $text];
            }

            $productivity = $this->buildProductivityInfo($agentId, (int) ($result['turns'] ?? 0));

            return ToolResult::success([
                'status' => $productivity['status'],
                'agentId' => $agentId,
                'agentType' => $agentType,
                'content' => $content,
                'totalDurationMs' => (int) $duration,
                'totalTokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
                'totalToolUseCount' => $productivity['totalToolUseCount'],
                'filesWritten' => $productivity['filesWritten'],
                'toolCallsByName' => $productivity['toolCallsByName'],
                'productivityWarning' => $productivity['productivityWarning'],
                'usage' => [
                    'input_tokens' => $usage['input_tokens'] ?? 0,
                    'output_tokens' => $usage['output_tokens'] ?? 0,
                    'cache_creation_input_tokens' => null,
                    'cache_read_input_tokens' => null,
                    'server_tool_use' => null,
                ],
                'prompt' => $prompt,
            ]);
        }

        // Fallback for InProcessBackend (fiber-based, not truly parallel)
        return $this->waitForFiberCompletion($agentId, $name, $prompt, $agentType);
    }

    /**
     * Legacy fiber-based wait (InProcessBackend fallback).
     */
    private function waitForFiberCompletion(
        string $agentId,
        string $name,
        string $prompt,
        string $agentType,
    ): ToolResult {
        $startTime = microtime(true);
        $maxWaitTime = 300;
        $coordinatorInstance = $this->coordinator ?? ParallelAgentCoordinator::getInstance();

        while (microtime(true) - $startTime < $maxWaitTime) {
            $tracker = $coordinatorInstance->getTracker($agentId);
            if ($tracker && $tracker->getStatus() === 'completed') {
                break;
            }

            $backend = $this->activeTasks[$agentId]['backend_instance'] ?? null;
            if ($backend) {
                $status = $backend->getStatus($agentId);
                if ($status === AgentStatus::COMPLETED || $status === AgentStatus::FAILED) {
                    break;
                }
                if ($backend instanceof InProcessBackend) {
                    $coordinatorInstance->processAllFibers();
                }
            }

            usleep(10_000);
        }

        $agentResult = $coordinatorInstance->getAgentResult($agentId);
        if (!$agentResult) {
            return ToolResult::failure('Agent execution timed out or failed');
        }

        $duration = (microtime(true) - $startTime) * 1000;
        $usage = $agentResult->totalUsage();

        $content = [];
        $text = $agentResult->text();
        if (!empty($text)) {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        $productivity = $this->buildProductivityInfo($agentId, count($agentResult->allResponses));

        return ToolResult::success([
            'status' => $productivity['status'],
            'agentId' => $agentId,
            'agentType' => $agentType,
            'content' => $content,
            'totalDurationMs' => (int) $duration,
            'totalTokens' => $usage->inputTokens + $usage->outputTokens,
            'totalToolUseCount' => $productivity['totalToolUseCount'],
            'filesWritten' => $productivity['filesWritten'],
            'toolCallsByName' => $productivity['toolCallsByName'],
            'productivityWarning' => $productivity['productivityWarning'],
            'usage' => [
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cache_creation_input_tokens' => null,
                'cache_read_input_tokens' => null,
                'server_tool_use' => null,
            ],
            'prompt' => $prompt,
        ]);
    }

    /**
     * Apply progress events from child process stderr to the tracker.
     *
     * Handles both CC-compatible NDJSON format (type: assistant/user/result)
     * and the legacy __PROGRESS__ format for backward compatibility.
     */
    private function applyProgressEvents(
        ProcessBackend $backend,
        string $agentId,
        \SuperAgent\Swarm\AgentProgressTracker $tracker,
    ): void {
        foreach ($backend->consumeProgressEvents($agentId) as $event) {
            $type = $event['type'] ?? '';

            switch ($type) {
                // ── CC NDJSON format ──────────────────────────────
                case 'assistant':
                    // Extract tool_use blocks from assistant message content
                    $content = $event['message']['content'] ?? [];
                    foreach ($content as $block) {
                        if (($block['type'] ?? '') === 'tool_use') {
                            $toolName = (string) ($block['name'] ?? 'unknown');
                            $toolInput = (array) ($block['input'] ?? []);
                            $tracker->addToolActivity([
                                'name' => $toolName,
                                'input' => $toolInput,
                            ]);
                            $this->recordToolUse($agentId, $toolName, $toolInput);
                        }
                    }
                    // Per-turn usage (SuperAgent extension on assistant events)
                    $usage = $event['usage'] ?? [];
                    if (!empty($usage)) {
                        $tracker->updateFromResponse([
                            'input_tokens' => $usage['inputTokens'] ?? 0,
                            'output_tokens' => $usage['outputTokens'] ?? 0,
                            'cache_creation_input_tokens' => $usage['cacheCreationInputTokens'] ?? 0,
                            'cache_read_input_tokens' => $usage['cacheReadInputTokens'] ?? 0,
                        ]);
                    }
                    break;

                case 'user':
                    // tool_result events — update token stats when paired with
                    // an assistant turn (token info comes from 'result' events)
                    break;

                case 'result':
                    // Final result — extract usage for token tracking
                    $usage = $event['usage'] ?? [];
                    if (!empty($usage)) {
                        $tracker->updateFromResponse([
                            'input_tokens' => $usage['inputTokens'] ?? 0,
                            'output_tokens' => $usage['outputTokens'] ?? 0,
                            'cache_creation_input_tokens' => $usage['cacheCreationInputTokens'] ?? 0,
                            'cache_read_input_tokens' => $usage['cacheReadInputTokens'] ?? 0,
                        ]);
                    }
                    break;

                // ── Legacy __PROGRESS__ format (backward compat) ─
                case 'tool_use':
                    $data = $event['data'] ?? [];
                    $toolName = (string) ($data['tool_name'] ?? 'unknown');
                    $toolInput = (array) ($data['input'] ?? []);
                    $tracker->addToolActivity([
                        'name' => $toolName,
                        'input' => $toolInput,
                    ]);
                    $this->recordToolUse($agentId, $toolName, $toolInput);
                    break;

                case 'turn':
                    $data = $event['data'] ?? [];
                    $tracker->updateFromResponse([
                        'input_tokens' => $data['input_tokens'] ?? 0,
                        'output_tokens' => $data['output_tokens'] ?? 0,
                        'cache_creation_input_tokens' => $data['cache_creation_input_tokens'] ?? 0,
                        'cache_read_input_tokens' => $data['cache_read_input_tokens'] ?? 0,
                    ]);
                    break;
            }
        }
    }

    /**
     * Accumulate a child's tool_use event into the per-agent productivity
     * counters so waitForProcessCompletion()/waitForFiberCompletion() can
     * report concrete evidence of work done rather than relying on the
     * child's self-reported success flag alone.
     *
     * Tool names treated as "file writes" map to inputs carrying a path — we
     * capture the path so the orchestrator knows exactly which files the
     * child created, and can catch the common /team failure mode where a
     * sub-agent returns success:true without persisting anything.
     */
    private function recordToolUse(string $agentId, string $toolName, array $toolInput): void
    {
        if (!isset($this->activeTasks[$agentId])) {
            return;
        }
        $this->activeTasks[$agentId]['tool_counts'][$toolName] =
            ($this->activeTasks[$agentId]['tool_counts'][$toolName] ?? 0) + 1;

        // Tool names that mean "I wrote something to disk". Covers both
        // Claude Code's vocabulary (Write/Edit/MultiEdit/NotebookEdit) and
        // the generic Create used by some generic-purpose agents.
        static $writeTools = ['Write', 'Edit', 'MultiEdit', 'NotebookEdit', 'Create'];
        if (in_array($toolName, $writeTools, true)) {
            $path = $toolInput['file_path'] ?? $toolInput['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $this->activeTasks[$agentId]['files_written'][] = $path;
            }
        }
    }

    /**
     * Build the productivity block appended to every ToolResult.
     *
     * Only `completed_empty` is a hard failure status — that's the classic
     * "model wrote prose instead of executing" case (zero tool calls).
     *
     * We deliberately do NOT elevate "called tools but didn't Write files"
     * to a failure status: many legitimate sub-agent patterns return
     * findings via the assistant's text (for parent consolidation) without
     * writing to disk. Advisory consults, pure-research pulls, Bash-only
     * smoke tests — all valid. We still surface `filesWritten` as an
     * informational list so the caller (SKILL policy layer) can enforce
     * "files are required for this particular task" where appropriate.
     *
     * History: an earlier revision flagged no-Write as `completed_no_writes`
     * (2026-04-22). MINIMAX-backed SuperAgent orchestrators read that status
     * as "child failed" and fell back to playing roles themselves in one
     * session — producing a single rushed report and skipping consolidation
     * entirely (RUN 72). Downgrading to a pure info field restored the
     * pre-regression behavior while keeping the zero-tool-calls check.
     *
     * @return array{
     *   status: string,
     *   filesWritten: list<string>,
     *   toolCallsByName: array<string,int>,
     *   totalToolUseCount: int,
     *   productivityWarning: ?string,
     * }
     */
    private function buildProductivityInfo(string $agentId, int $childReportedTurns): array
    {
        $task = $this->activeTasks[$agentId] ?? ['tool_counts' => [], 'files_written' => [], 'prompt' => '', 'output_subdir' => null];
        $counts = $task['tool_counts'];
        $files = array_values(array_unique($task['files_written']));
        $observedToolCalls = array_sum($counts);

        // Prefer observed tool-use count over child-reported turns: turns
        // counts assistant turns, not tool calls. When observed >0 we know
        // the child really did invoke tools.
        $totalToolUseCount = $observedToolCalls > 0 ? $observedToolCalls : $childReportedTurns;

        $isCjk = \SuperAgent\Support\LanguageDetector::isCjk($task['prompt'] ?? '');

        $status = 'completed';
        $warning = null;
        if ($observedToolCalls === 0) {
            $status  = 'completed_empty';
            $warning = $isCjk
                ? '子代理零工具调用。最终文本就是全部输出；没有创建文件，也没有执行任何命令。通常说明模型在"描述"要做什么而不是"去做"——请用更明确的指令重新派发，或换更强的模型。'
                : 'Child made zero tool calls. The final text is the entire output; no files were created, no commands were run. This usually means the model described what it would do instead of doing it — retry with a more explicit instruction to invoke tools, or pick a stronger model.';
        } elseif ($files === []) {
            // Keep `status: completed`. Attach an informational note so the
            // caller has context, but do NOT block: some sub-agent patterns
            // legitimately return findings as text without persisting files.
            $warning = $isCjk
                ? sprintf(
                    '提示：子代理调用了 %d 次工具但没有写入任何文件。若本任务预期生成报告/CSV 到磁盘，请检查子代理的最终文本（可能把结果放在正文里），或用显式 write 指令重新派发。否则可忽略——咨询类子任务常常只通过文本返回结果。',
                    $observedToolCalls
                )
                : 'Note: child invoked ' . $observedToolCalls . ' tool(s) but wrote no files. If this task was expected to produce reports/CSVs on disk, inspect the child text (it may contain the answer inline) or re-dispatch with an explicit write instruction. Otherwise this is fine — advisory consultations often return results via text only.';
        }

        // Optional post-exit filesystem audit, gated on output_subdir.
        // Observational only — warnings land in the result for the
        // orchestrator to decide what to do. Auditor never throws
        // (missing dir returns []), so the happy path is untouched.
        $outputWarnings = [];
        $outputSubdir = $task['output_subdir'] ?? null;
        if (is_string($outputSubdir) && $outputSubdir !== '') {
            $auditor = new AgentOutputAuditor();
            $outputWarnings = $auditor->audit($outputSubdir, $task['name'] ?? $agentId);
        }

        return [
            'status'              => $status,
            'filesWritten'        => $files,
            'toolCallsByName'     => $counts,
            'totalToolUseCount'   => $totalToolUseCount,
            'productivityWarning' => $warning,
            'outputWarnings'      => $outputWarnings,
        ];
    }

    public function setTeamContext(TeamContext $context): void
    {
        $this->teamContext = $context;
    }

    private function initializeBackends(): void
    {
        $this->backends[BackendType::PROCESS->value] = new ProcessBackend(null, $this->logger);
        $this->backends[BackendType::IN_PROCESS->value] = new InProcessBackend($this->logger);
    }

    private function getProcessBackend(): ProcessBackend
    {
        return $this->backends[BackendType::PROCESS->value];
    }

    private function getBackend(BackendType $type): BackendInterface
    {
        if (!isset($this->backends[$type->value])) {
            throw new \RuntimeException("Backend '{$type->value}' not initialized");
        }
        return $this->backends[$type->value];
    }

    /** Aliases for backward-compatible schema values → canonical enum values. */
    private const MODE_ALIASES = [
        'bypass' => 'bypassPermissions',
    ];

    /**
     * Build the enum array for the 'mode' schema field.
     * Canonical values from PermissionMode + aliases for backward compat.
     */
    private static function permissionModeEnum(): array
    {
        $values = array_column(PermissionMode::cases(), 'value');
        return array_values(array_unique(array_merge($values, array_keys(self::MODE_ALIASES))));
    }

    /**
     * Resolve permission mode from input string, mapping schema aliases
     * to PermissionMode enum values.
     */
    private static function resolvePermissionMode(string $mode): PermissionMode
    {
        $resolved = self::MODE_ALIASES[$mode] ?? $mode;

        try {
            return PermissionMode::from($resolved);
        } catch (\ValueError) {
            return PermissionMode::DEFAULT;
        }
    }

    private function generateAgentName(string $type): string
    {
        $prefix = match ($type) {
            'code-writer' => 'coder',
            'researcher' => 'researcher',
            'reviewer' => 'reviewer',
            default => 'agent',
        };
        return $prefix . '_' . substr(uniqid(), -6);
    }

    /**
     * Check status of active agents.
     */
    public function checkActiveAgents(): array
    {
        $statuses = [];
        foreach ($this->activeTasks as $agentId => $info) {
            $backend = $info['backend_instance'];
            $status = $backend->getStatus($agentId);
            $statuses[$agentId] = [
                'name' => $info['name'],
                'status' => $status?->value ?? 'unknown',
                'started_at' => $info['started_at']->format(\DateTimeInterface::ATOM),
            ];
        }
        return $statuses;
    }

    /**
     * Kill an agent.
     */
    public function killAgent(string $agentId): bool
    {
        if (!isset($this->activeTasks[$agentId])) {
            return false;
        }

        $info = $this->activeTasks[$agentId];
        $info['backend_instance']->kill($agentId);

        if ($this->teamContext) {
            $this->teamContext->removeMember($agentId);
            $this->teamContext->save();
        }

        unset($this->activeTasks[$agentId]);
        return true;
    }
}
