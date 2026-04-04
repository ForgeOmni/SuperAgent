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

    public function __construct(
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
               'The agent runs independently and can use tools to complete its task.';
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
                    'enum' => AgentManager::getInstance()->getNames(),
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
                    'enum' => ['default', 'plan', 'acceptEdits', 'bypass', 'dontAsk', 'auto'],
                ],
                'isolation' => [
                    'type' => 'string',
                    'description' => 'Isolation mode. "worktree" creates a temporary git worktree',
                    'enum' => ['none', 'worktree', 'container'],
                ],
                'run_in_background' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to run this agent in the background',
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
            $mode = isset($input['mode']) ? PermissionMode::from($input['mode']) : null;
            $isolation = isset($input['isolation']) ? IsolationMode::from($input['isolation']) : IsolationMode::NONE;
            $runInBackground = $input['run_in_background'] ?? false;

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
            $definition = AgentManager::getInstance()->get($subagentType);

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

        // If it's a ProcessBackend, use its poll/waitAll mechanism
        if ($backend instanceof ProcessBackend) {
            while (microtime(true) - $startTime < $maxWaitTime) {
                $statuses = $backend->poll();

                $status = $statuses[$agentId] ?? null;
                if ($status === AgentStatus::COMPLETED || $status === AgentStatus::FAILED) {
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

            return ToolResult::success([
                'status' => 'completed',
                'agentId' => $agentId,
                'agentType' => $agentType,
                'content' => $content,
                'totalDurationMs' => (int) $duration,
                'totalTokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
                'totalToolUseCount' => $result['turns'] ?? 0,
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
        $coordinator = ParallelAgentCoordinator::getInstance();

        while (microtime(true) - $startTime < $maxWaitTime) {
            $tracker = $coordinator->getTracker($agentId);
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
                    $coordinator->processAllFibers();
                }
            }

            usleep(10_000);
        }

        $agentResult = $coordinator->getAgentResult($agentId);
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

        return ToolResult::success([
            'status' => 'completed',
            'agentId' => $agentId,
            'agentType' => $agentType,
            'content' => $content,
            'totalDurationMs' => (int) $duration,
            'totalTokens' => $usage->inputTokens + $usage->outputTokens,
            'totalToolUseCount' => count($agentResult->allResponses),
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
