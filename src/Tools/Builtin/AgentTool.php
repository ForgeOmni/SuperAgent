<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Permissions\PermissionMode;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\IsolationMode;
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
                    'enum' => ['general-purpose', 'code-writer', 'researcher', 'reviewer'],
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
            
            // Prepare system prompt based on agent type
            $systemPrompt = $this->getSystemPromptForType($subagentType);
            
            // Prepare allowed tools based on agent type
            $allowedTools = $this->getAllowedToolsForType($subagentType);
            
            // Create spawn configuration
            $config = new AgentSpawnConfig(
                name: $name,
                prompt: $prompt,
                teamName: $teamName,
                model: $model,
                systemPrompt: $systemPrompt,
                permissionMode: $mode,
                backend: $backendType,
                isolation: $isolation,
                runInBackground: $runInBackground,
                allowedTools: $allowedTools,
                planModeRequired: $mode === PermissionMode::PLAN,
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
            
            // Prepare output
            $output = [
                'success' => true,
                'agent_id' => $result->agentId,
                'task_id' => $result->taskId,
                'name' => $name,
                'backend' => $backendType->value,
                'message' => $runInBackground
                    ? "Agent '{$name}' started in background. You'll be notified when it completes."
                    : "Agent '{$name}' started successfully.",
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
     * Get system prompt for agent type.
     */
    private function getSystemPromptForType(string $type): ?string
    {
        return match ($type) {
            'code-writer' => 'You are a code-writing specialist. Focus on implementing clean, efficient, and well-tested code.',
            'researcher' => 'You are a research specialist. Focus on gathering information, analyzing data, and providing comprehensive findings.',
            'reviewer' => 'You are a code review specialist. Focus on identifying issues, suggesting improvements, and ensuring code quality.',
            default => null,
        };
    }
    
    /**
     * Get allowed tools for agent type.
     */
    private function getAllowedToolsForType(string $type): ?array
    {
        return match ($type) {
            'code-writer' => ['read_file', 'write_file', 'edit_file', 'bash', 'grep', 'glob'],
            'researcher' => ['read_file', 'bash', 'grep', 'glob', 'web_search', 'web_fetch'],
            'reviewer' => ['read_file', 'grep', 'glob', 'bash'],
            default => null, // All tools allowed
        };
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