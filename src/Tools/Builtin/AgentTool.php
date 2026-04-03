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
 */
class AgentTool extends Tool
{
    private array $backends = [];
    private ?TeamContext $teamContext = null;
    private LoggerInterface $logger;
    private array $activeTasks = [];
    
    public function __construct(
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->initializeBackends();
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
                'backend' => [
                    'type' => 'string',
                    'description' => 'Backend to use for execution',
                    'enum' => ['in-process', 'process', 'docker'],
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
            // Extract input parameters
            $description = $input['description'];
            $prompt = $input['prompt'];
            $subagentType = $input['subagent_type'] ?? 'general-purpose';
            $name = $input['name'] ?? $this->generateAgentName($subagentType);
            $teamName = $input['team_name'] ?? $this->teamContext?->getTeamName();
            $model = $input['model'] ?? null;
            $mode = isset($input['mode']) ? PermissionMode::from($input['mode']) : null;
            $isolation = isset($input['isolation']) ? IsolationMode::from($input['isolation']) : IsolationMode::NONE;
            $runInBackground = $input['run_in_background'] ?? false;
            $backendType = isset($input['backend']) ? BackendType::from($input['backend']) : BackendType::IN_PROCESS;
            
            $this->logger->info("Spawning agent", [
                'name' => $name,
                'type' => $subagentType,
                'backend' => $backendType->value,
                'team' => $teamName,
            ]);
            
            // Get or create team context
            if ($teamName && !$this->teamContext) {
                $this->teamContext = TeamContext::load($teamName);
                if (!$this->teamContext) {
                    // Create new team with current agent as leader
                    $leaderId = 'leader_' . uniqid();
                    $this->teamContext = new TeamContext($teamName, $leaderId);
                    $this->teamContext->save();
                }
            }
            
            // Get appropriate backend
            $backend = $this->getBackend($backendType);
            if (!$backend->isAvailable()) {
                return ToolResult::failure(
                    "Backend '{$backendType->value}' is not available on this system"
                );
            }
            
            // Set team context for in-process backend
            if ($backend instanceof InProcessBackend && $this->teamContext) {
                $backend->setTeamContext($this->teamContext);
            }
            
            // Resolve agent definition
            $definition = AgentManager::getInstance()->get($subagentType);

            // Prepare spawn configuration from agent definition
            $config = new AgentSpawnConfig(
                name: $name,
                prompt: $prompt,
                teamName: $teamName,
                model: $model,
                systemPrompt: $definition?->systemPrompt(),
                permissionMode: $mode,
                backend: $backendType,
                isolation: $isolation,
                runInBackground: $runInBackground,
                allowedTools: $definition?->allowedTools(),
                deniedTools: $definition?->disallowedTools(),
                planModeRequired: $mode === PermissionMode::PLAN,
                readOnly: $definition?->readOnly() ?? false,
            );
            
            // Spawn the agent
            $result = $backend->spawn($config);
            
            if (!$result->success) {
                return ToolResult::failure(
                    "Failed to spawn agent: " . ($result->error ?? 'Unknown error')
                );
            }
            
            // Register with team if applicable
            if ($this->teamContext) {
                $member = new TeamMember(
                    agentId: $result->agentId,
                    name: $name,
                    backend: $backendType,
                    taskId: $result->taskId,
                    pid: $result->pid,
                    status: AgentStatus::RUNNING,
                );
                $this->teamContext->addMember($member);
                $this->teamContext->save();
            }
            
            // Track active task
            $this->activeTasks[$result->agentId] = [
                'task_id' => $result->taskId,
                'name' => $name,
                'backend' => $backendType,
                'started_at' => new \DateTimeImmutable(),
            ];
            
            // If running synchronously (not in background), wait for completion
            if (!$runInBackground && $backendType === BackendType::IN_PROCESS) {
                return $this->waitForSynchronousCompletion($result->agentId, $name, $prompt, $subagentType);
            }
            
            // Prepare async output
            $output = [
                'status' => 'async_launched',
                'agentId' => $result->agentId,
                'task_id' => $result->taskId,
                'description' => $params['description'] ?? "Execute agent: $name",
                'prompt' => $prompt,
                'name' => $name,
                'backend' => $backendType->value,
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
            $this->logger->error("Failed to spawn agent", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return ToolResult::failure(
                "Failed to spawn agent: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Set the team context for this tool.
     */
    public function setTeamContext(TeamContext $context): void
    {
        $this->teamContext = $context;
    }
    
    /**
     * Initialize available backends.
     */
    private function initializeBackends(): void
    {
        $this->backends[BackendType::IN_PROCESS->value] = new InProcessBackend($this->logger);
        $this->backends[BackendType::PROCESS->value] = new ProcessBackend(null, $this->logger);
    }
    
    /**
     * Get a backend by type.
     */
    private function getBackend(BackendType $type): BackendInterface
    {
        if (!isset($this->backends[$type->value])) {
            throw new \RuntimeException("Backend '{$type->value}' not initialized");
        }
        
        return $this->backends[$type->value];
    }
    
    /**
     * Generate a unique agent name.
     */
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
            $backend = $this->getBackend($info['backend']);
            $status = $backend->getStatus($agentId);
            
            $statuses[$agentId] = [
                'name' => $info['name'],
                'status' => $status?->value ?? 'unknown',
                'backend' => $info['backend']->value,
                'started_at' => $info['started_at']->format(\DateTimeInterface::ATOM),
            ];
        }
        
        return $statuses;
    }
    
    /**
     * Wait for synchronous agent completion and return Claude Code format result.
     */
    private function waitForSynchronousCompletion(
        string $agentId,
        string $name,
        string $prompt,
        string $agentType
    ): ToolResult {
        $startTime = microtime(true);
        $maxWaitTime = 300; // 5 minutes max wait
        
        // Get the coordinator to track progress
        $coordinator = ParallelAgentCoordinator::getInstance();
        
        // Wait for the agent to complete
        while (microtime(true) - $startTime < $maxWaitTime) {
            // Check if agent has completed
            $tracker = $coordinator->getTracker($agentId);
            if ($tracker && $tracker->getStatus() === 'completed') {
                break;
            }
            
            // Check backend status
            $status = $this->activeTasks[$agentId]['backend']->getStatus($agentId);
            if ($status === AgentStatus::COMPLETED || $status === AgentStatus::FAILED) {
                break;
            }
            
            // Allow fibers to execute
            if (isset($this->activeTasks[$agentId]['backend']) && 
                $this->activeTasks[$agentId]['backend'] instanceof InProcessBackend) {
                $coordinator->executeFibers();
            }
            
            // Small delay to avoid busy waiting
            usleep(10000); // 10ms
        }
        
        // Get the result from the coordinator
        $agentResult = $coordinator->getAgentResult($agentId);
        
        if (!$agentResult) {
            return ToolResult::failure("Agent execution timed out or failed");
        }
        
        // Calculate metrics
        $duration = (microtime(true) - $startTime) * 1000; // Convert to ms
        $usage = $agentResult->totalUsage();
        $totalTokens = $usage->inputTokens + $usage->outputTokens;
        
        // Format content blocks like Claude Code
        $content = [];
        $text = $agentResult->text();
        if (!empty($text)) {
            $content[] = ['type' => 'text', 'text' => $text];
        }
        
        // Count tool uses (simplified for now)
        $toolUseCount = count($agentResult->allResponses);
        
        // Return in Claude Code AgentToolResult format
        return ToolResult::success([
            'status' => 'completed',
            'agentId' => $agentId,
            'agentType' => $agentType,
            'content' => $content,
            'totalDurationMs' => (int)$duration,
            'totalTokens' => $totalTokens,
            'totalToolUseCount' => $toolUseCount,
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
     * Kill an agent.
     */
    public function killAgent(string $agentId): bool
    {
        if (!isset($this->activeTasks[$agentId])) {
            return false;
        }
        
        $info = $this->activeTasks[$agentId];
        $backend = $this->getBackend($info['backend']);
        
        $backend->kill($agentId);
        
        // Remove from team context
        if ($this->teamContext) {
            $this->teamContext->removeMember($agentId);
            $this->teamContext->save();
        }
        
        unset($this->activeTasks[$agentId]);
        
        return true;
    }
}