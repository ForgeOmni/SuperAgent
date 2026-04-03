<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Backends;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Agent\Agent;
use SuperAgent\Context\Context;
use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentSpawnResult;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\AgentTask;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\TeamContext;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\TeamMember;

/**
 * In-process backend for running agents in the same PHP process.
 * Uses fibers/generators for concurrent execution.
 */
class InProcessBackend implements BackendInterface
{
    private array $agents = [];
    private array $tasks = [];
    private array $fibers = [];
    private LoggerInterface $logger;
    private ?TeamContext $teamContext = null;
    private ParallelAgentCoordinator $coordinator;
    
    public function __construct(
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->coordinator = ParallelAgentCoordinator::getInstance($this->logger);
    }
    
    public function setTeamContext(TeamContext $context): void
    {
        $this->teamContext = $context;
        $this->coordinator->registerTeam($context);
    }
    
    public function getType(): BackendType
    {
        return BackendType::IN_PROCESS;
    }
    
    public function isAvailable(): bool
    {
        // In-process is always available
        return true;
    }
    
    public function spawn(AgentSpawnConfig $config): AgentSpawnResult
    {
        try {
            // Generate unique agent ID
            $agentId = $this->generateAgentId($config->name);
            $taskId = uniqid('task_', true);
            
            $this->logger->debug("Spawning in-process agent", [
                'agent_id' => $agentId,
                'task_id' => $taskId,
                'name' => $config->name,
            ]);
            
            // Create agent context
            $context = new Context();
            
            // Set team context if available
            if ($this->teamContext) {
                $context->setMetadata('team_name', $this->teamContext->getTeamName());
                $context->setMetadata('agent_name', $config->name);
                $context->setMetadata('agent_id', $agentId);
                $context->setMetadata('agent_color', $config->color);
            }
            
            // Apply permission mode if specified
            if ($config->permissionMode !== null) {
                $context->setMetadata('permission_mode', $config->permissionMode->value);
            }
            
            // Create the agent
            $agent = new Agent(
                context: $context,
                logger: $this->logger,
            );
            
            // Configure agent based on spawn config
            if ($config->model !== null) {
                $agent->setModel($config->model);
            }
            
            if ($config->systemPrompt !== null) {
                $agent->setSystemPrompt($config->systemPrompt);
            }
            
            if ($config->allowedTools !== null) {
                $agent->setAllowedTools($config->allowedTools);
            }
            
            // Store agent and task info
            $this->agents[$agentId] = $agent;
            $task = new AgentTask(
                taskId: $taskId,
                agentId: $agentId,
                agentName: $config->name,
                status: AgentStatus::PENDING,
                backend: BackendType::IN_PROCESS,
                teamName: $config->teamName,
                startedAt: new \DateTimeImmutable(),
            );
            $this->tasks[$taskId] = $task;
            
            // Register with coordinator for progress tracking
            $this->coordinator->registerAgent(
                $agentId,
                $config->name,
                $config->teamName,
                $task
            );
            
            // Start agent execution in background if requested
            if ($config->runInBackground) {
                $this->startAgentExecution($agentId, $config->prompt);
            }
            
            return new AgentSpawnResult(
                success: true,
                agentId: $agentId,
                taskId: $taskId,
            );
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to spawn in-process agent", [
                'error' => $e->getMessage(),
                'name' => $config->name,
            ]);
            
            return new AgentSpawnResult(
                success: false,
                agentId: '',
                error: $e->getMessage(),
            );
        }
    }
    
    public function sendMessage(string $agentId, AgentMessage $message): void
    {
        if (!isset($this->agents[$agentId])) {
            throw new \RuntimeException("Agent $agentId not found");
        }
        
        // Get the mailbox for this agent
        $mailbox = $this->getMailboxPath($agentId);
        
        // Write message to mailbox file
        $this->writeToMailbox($mailbox, $message);
        
        $this->logger->debug("Message sent to agent", [
            'agent_id' => $agentId,
            'from' => $message->from,
            'summary' => $message->summary,
        ]);
    }
    
    public function requestShutdown(string $agentId, ?string $reason = null): void
    {
        if (!isset($this->agents[$agentId])) {
            return;
        }
        
        // Update task status
        foreach ($this->tasks as $task) {
            if ($task->agentId === $agentId) {
                $this->updateTaskStatus($task->taskId, AgentStatus::CANCELLED);
                break;
            }
        }
        
        // Stop the fiber if running
        if (isset($this->fibers[$agentId])) {
            // PHP Fibers don't have a direct stop mechanism
            // We'll rely on the agent checking for cancellation
            $this->agents[$agentId]->getContext()->setMetadata('cancelled', true);
        }
        
        $this->logger->info("Shutdown requested for agent", [
            'agent_id' => $agentId,
            'reason' => $reason,
        ]);
    }
    
    public function kill(string $agentId): void
    {
        $this->requestShutdown($agentId, 'Force kill requested');
        
        // Clean up immediately
        $this->cleanup($agentId);
    }
    
    public function getStatus(string $agentId): ?AgentStatus
    {
        foreach ($this->tasks as $task) {
            if ($task->agentId === $agentId) {
                return $task->status;
            }
        }
        
        return null;
    }
    
    public function isRunning(string $agentId): bool
    {
        $status = $this->getStatus($agentId);
        return $status === AgentStatus::RUNNING || $status === AgentStatus::PENDING;
    }
    
    public function cleanup(string $agentId): void
    {
        // Unregister from coordinator
        $this->coordinator->unregisterAgent($agentId);
        
        // Remove agent
        unset($this->agents[$agentId]);
        unset($this->fibers[$agentId]);
        
        // Remove task
        foreach ($this->tasks as $taskId => $task) {
            if ($task->agentId === $agentId) {
                unset($this->tasks[$taskId]);
                break;
            }
        }
        
        // Remove mailbox
        $mailbox = $this->getMailboxPath($agentId);
        if (file_exists($mailbox)) {
            unlink($mailbox);
        }
        
        $this->logger->debug("Cleaned up agent resources", [
            'agent_id' => $agentId,
        ]);
    }
    
    /**
     * Start agent execution in a fiber.
     */
    private function startAgentExecution(string $agentId, string $prompt): void
    {
        if (!isset($this->agents[$agentId])) {
            return;
        }
        
        $agent = $this->agents[$agentId];
        
        // Update task status
        $this->updateTaskStatus($agentId, AgentStatus::RUNNING);
        
        // Create a fiber for concurrent execution
        $fiber = new \Fiber(function() use ($agent, $agentId, $prompt) {
            try {
                // Get progress tracker
                $tracker = $this->coordinator->getTracker($agentId);
                
                // Set activity
                if ($tracker) {
                    $tracker->setCurrentActivity("Starting agent execution");
                }
                
                // Execute the agent with the prompt
                $result = $agent->run($prompt);
                
                // Store the result in the coordinator
                $this->coordinator->storeAgentResult($agentId, $result);
                
                // Update tracker with final usage from the AgentResult
                if ($tracker && $result instanceof \SuperAgent\AgentResult) {
                    $usage = $result->totalUsage();
                    $tracker->updateFromResponse(
                        ['input_tokens' => $usage->inputTokens, 'output_tokens' => $usage->outputTokens],
                        null
                    );
                }
                
                // Update status on completion
                $this->updateTaskStatus($agentId, AgentStatus::COMPLETED);
                
                if ($tracker) {
                    $tracker->complete();
                }
                
                $this->logger->info("Agent completed execution", [
                    'agent_id' => $agentId,
                    'response_text_length' => strlen($result->text()),
                    'total_tokens' => $tracker ? $tracker->getTotalTokens() : 0,
                    'turns' => $result->turns(),
                    'cost_usd' => $result->totalCostUsd,
                ]);
                
            } catch (\Exception $e) {
                // Update status on failure
                $this->updateTaskStatus($agentId, AgentStatus::FAILED);
                
                // Mark tracker as completed
                $tracker = $this->coordinator->getTracker($agentId);
                if ($tracker) {
                    $tracker->complete();
                }
                
                $this->logger->error("Agent execution failed", [
                    'agent_id' => $agentId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
        
        $this->fibers[$agentId] = $fiber;
        $this->coordinator->registerFiber($agentId, $fiber);
        $fiber->start();
    }
    
    /**
     * Generate a unique agent ID.
     */
    private function generateAgentId(string $name): string
    {
        return sprintf(
            '%s_%s_%s',
            preg_replace('/[^a-zA-Z0-9]/', '_', $name),
            uniqid(),
            bin2hex(random_bytes(4))
        );
    }
    
    /**
     * Get the mailbox file path for an agent.
     */
    private function getMailboxPath(string $agentId): string
    {
        $mailboxDir = sys_get_temp_dir() . '/superagent_mailboxes';
        if (!is_dir($mailboxDir)) {
            mkdir($mailboxDir, 0755, true);
        }
        
        return $mailboxDir . '/' . $agentId . '.mailbox';
    }
    
    /**
     * Write a message to a mailbox file.
     */
    private function writeToMailbox(string $mailboxPath, AgentMessage $message): void
    {
        $messages = [];
        
        // Read existing messages
        if (file_exists($mailboxPath)) {
            $content = file_get_contents($mailboxPath);
            if ($content) {
                $messages = json_decode($content, true) ?? [];
            }
        }
        
        // Add new message
        $messages[] = $message->toArray();
        
        // Keep only last 100 messages
        if (count($messages) > 100) {
            $messages = array_slice($messages, -100);
        }
        
        // Write back
        file_put_contents($mailboxPath, json_encode($messages, JSON_PRETTY_PRINT));
    }
    
    /**
     * Update task status.
     */
    private function updateTaskStatus(string $taskId, AgentStatus $status): void
    {
        foreach ($this->tasks as &$task) {
            if ($task->taskId === $taskId || $task->agentId === $taskId) {
                $task = new AgentTask(
                    taskId: $task->taskId,
                    agentId: $task->agentId,
                    agentName: $task->agentName,
                    status: $status,
                    backend: $task->backend,
                    teamName: $task->teamName,
                    startedAt: $task->startedAt,
                    completedAt: in_array($status, [AgentStatus::COMPLETED, AgentStatus::FAILED, AgentStatus::CANCELLED]) ? new \DateTimeImmutable() : null,
                );
                break;
            }
        }
    }
    
    /**
     * Process messages in the background for all agents.
     */
    public function processMessages(): void
    {
        // Use coordinator to process all fibers
        $this->coordinator->processAllFibers();
        
        // Also handle mailbox-based messages
        foreach ($this->fibers as $agentId => $fiber) {
            if ($fiber->isSuspended()) {
                // Check for new messages
                $mailbox = $this->getMailboxPath($agentId);
                if (file_exists($mailbox)) {
                    // Resume fiber to process messages
                    $fiber->resume();
                }
            }
        }
    }
    
    /**
     * Get parallel execution progress for all running agents.
     */
    public function getParallelProgress(): array
    {
        return $this->coordinator->getConsolidatedProgress();
    }
    
    /**
     * Get hierarchical display of running agents.
     */
    public function getHierarchicalDisplay(): array
    {
        return $this->coordinator->getHierarchicalDisplay();
    }
}