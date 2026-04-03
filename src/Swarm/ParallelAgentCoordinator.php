<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Swarm\BackendType;

/**
 * Coordinates multiple agents running in parallel, similar to Claude Code's team management.
 * Tracks all running agents and their individual progress.
 */
class ParallelAgentCoordinator
{
    private static ?self $instance = null;
    
    /** @var array<string, AgentProgressTracker> */
    private array $trackers = [];
    
    /** @var array<string, AgentTask> */
    private array $runningTasks = [];
    
    /** @var array<string, TeamContext> */
    private array $teams = [];
    
    private LoggerInterface $logger;
    
    /** @var array<string, \Fiber> */
    private array $fibers = [];
    
    /** @var array<string, array> Pending messages for each agent */
    private array $pendingMessages = [];
    
    /** @var array<string, \SuperAgent\AgentResult> Completed agent results */
    private array $agentResults = [];
    
    /** @var \DateTimeImmutable|null Execution start time for tracking duration */
    private ?\DateTimeImmutable $executionStartTime = null;
    
    private function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }
    
    public static function getInstance(?LoggerInterface $logger = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logger);
        }
        return self::$instance;
    }
    
    /**
     * Start tracking execution time.
     */
    public function startExecution(): void
    {
        $this->executionStartTime = new \DateTimeImmutable();
    }
    
    /**
     * Register a new agent and create its progress tracker.
     */
    public function registerAgent(
        string $agentId,
        string $agentName,
        ?string $teamName = null,
        ?AgentTask $task = null
    ): AgentProgressTracker {
        $tracker = new AgentProgressTracker($agentId, $agentName);
        $this->trackers[$agentId] = $tracker;
        
        if ($task) {
            $this->runningTasks[$agentId] = $task;
        }
        
        if ($teamName && isset($this->teams[$teamName])) {
            $member = new TeamMember(
                agentId: $agentId,
                name: $agentName,
                backend: BackendType::IN_PROCESS,
                status: AgentStatus::RUNNING,
            );
            $this->teams[$teamName]->addMember($member);
        }
        
        $this->logger->info("Registered agent for parallel tracking", [
            'agent_id' => $agentId,
            'agent_name' => $agentName,
            'team' => $teamName,
        ]);
        
        return $tracker;
    }
    
    /**
     * Unregister an agent when it completes or fails.
     */
    public function unregisterAgent(string $agentId): void
    {
        if (isset($this->trackers[$agentId])) {
            $this->trackers[$agentId]->complete();
        }
        
        unset($this->runningTasks[$agentId]);
        unset($this->fibers[$agentId]);
        unset($this->pendingMessages[$agentId]);
        
        // Remove from team if applicable
        foreach ($this->teams as $team) {
            $team->removeMember($agentId);
        }
        
        $this->logger->info("Unregistered agent from parallel tracking", [
            'agent_id' => $agentId,
        ]);
    }
    
    /**
     * Get progress tracker for a specific agent.
     */
    public function getTracker(string $agentId): ?AgentProgressTracker
    {
        return $this->trackers[$agentId] ?? null;
    }
    
    /**
     * Get all active progress trackers.
     */
    public function getActiveTrackers(): array
    {
        $active = [];
        foreach ($this->trackers as $agentId => $tracker) {
            // If there's a task, check its status
            if (isset($this->runningTasks[$agentId])) {
                $task = $this->runningTasks[$agentId];
                if (in_array($task->status, [AgentStatus::RUNNING, AgentStatus::PENDING])) {
                    $active[$agentId] = $tracker;
                }
            } else {
                // No task associated, consider tracker active if not completed
                $progress = $tracker->getProgress();
                if ($progress['completedAt'] === null) {
                    $active[$agentId] = $tracker;
                }
            }
        }
        return $active;
    }
    
    /**
     * Get consolidated progress for all running agents.
     */
    public function getConsolidatedProgress(): array
    {
        $totalTokens = 0;
        $totalToolUses = 0;
        $agentProgress = [];
        
        foreach ($this->getActiveTrackers() as $agentId => $tracker) {
            $progress = $tracker->getProgress();
            $totalTokens += $progress['tokenCount'];
            $totalToolUses += $progress['toolUseCount'];
            $agentProgress[] = $progress;
        }
        
        return [
            'totalAgents' => count($agentProgress),
            'totalTokens' => $totalTokens,
            'totalToolUses' => $totalToolUses,
            'agents' => $agentProgress,
            'teams' => $this->getTeamsSummary(),
        ];
    }
    
    /**
     * Update agent activity.
     */
    public function updateAgentActivity(string $agentId, string $activity): void
    {
        $tracker = $this->getTracker($agentId);
        if ($tracker) {
            $tracker->setCurrentActivity($activity);
        }
    }
    
    /**
     * Register a fiber for an agent.
     */
    public function registerFiber(string $agentId, \Fiber $fiber): void
    {
        $this->fibers[$agentId] = $fiber;
    }
    
    /**
     * Process all agent fibers (resume suspended ones).
     */
    public function processAllFibers(): void
    {
        foreach ($this->fibers as $agentId => $fiber) {
            if ($fiber->isSuspended()) {
                // Check for pending messages
                if (!empty($this->pendingMessages[$agentId])) {
                    $messages = $this->pendingMessages[$agentId];
                    $this->pendingMessages[$agentId] = [];
                    
                    // Resume with messages
                    try {
                        $fiber->resume($messages);
                    } catch (\FiberError $e) {
                        $this->logger->error("Failed to resume fiber", [
                            'agent_id' => $agentId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } elseif ($fiber->isTerminated()) {
                // Clean up terminated fibers
                unset($this->fibers[$agentId]);
            }
        }
    }
    
    /**
     * Queue a message for an agent.
     */
    public function queueMessage(string $agentId, array $message): void
    {
        if (!isset($this->pendingMessages[$agentId])) {
            $this->pendingMessages[$agentId] = [];
        }
        $this->pendingMessages[$agentId][] = $message;
    }
    
    /**
     * Register a team.
     */
    public function registerTeam(TeamContext $team): void
    {
        $this->teams[$team->getTeamName()] = $team;
    }
    
    /**
     * Get team context.
     */
    public function getTeam(string $teamName): ?TeamContext
    {
        return $this->teams[$teamName] ?? null;
    }
    
    /**
     * Get teams summary.
     */
    private function getTeamsSummary(): array
    {
        $summary = [];
        foreach ($this->teams as $name => $team) {
            $summary[$name] = [
                'name' => $name,
                'leaderId' => $team->getLeaderId(),
                'memberCount' => $team->getMemberCount(),
                'activeMembers' => count($team->getActiveMembers()),
            ];
        }
        return $summary;
    }
    
    /**
     * Collect results from all tracked agents and create an AgentTeamResult.
     */
    public function collectTeamResults(): \SuperAgent\AgentTeamResult
    {
        $agentResults = [];
        $metadata = [
            'agents' => [],
            'teams' => [],
            'execution_start' => $this->executionStartTime ?? new \DateTimeImmutable(),
            'execution_time' => 0,
        ];
        
        // Collect results from each tracker
        foreach ($this->trackers as $agentId => $tracker) {
            // Get the agent's result if available
            if (isset($this->agentResults[$agentId])) {
                $agentResults[] = $this->agentResults[$agentId];
                
                // Get agent metadata
                $progress = $tracker->getProgress();
                $agentName = $tracker->getAgentName();
                
                $metadata['agents'][$agentName] = [
                    'agent_id' => $agentId,
                    'status' => $progress['status'],
                    'turns' => $progress['turnCount'],
                    'tokens' => $progress['tokenCount'],
                    'tool_uses' => $progress['toolUseCount'],
                    'last_activity' => $progress['currentActivity'],
                ];
            }
        }
        
        // Add team information
        foreach ($this->teams as $teamName => $team) {
            $metadata['teams'][$teamName] = [
                'leader_id' => $team->getLeaderId(),
                'member_count' => $team->getMemberCount(),
                'active_members' => count($team->getActiveMembers()),
            ];
        }
        
        // Calculate total execution time
        if ($this->executionStartTime) {
            $now = new \DateTimeImmutable();
            $metadata['execution_time'] = $now->getTimestamp() - $this->executionStartTime->getTimestamp();
        }
        
        return new \SuperAgent\AgentTeamResult($agentResults, $metadata);
    }
    
    /**
     * Store a result for an agent.
     */
    public function storeAgentResult(string $agentId, \SuperAgent\AgentResult $result): void
    {
        $this->agentResults[$agentId] = $result;
        
        // Update tracker with final status
        if (isset($this->trackers[$agentId])) {
            $this->trackers[$agentId]->setStatus('completed');
        }
    }
    
    /**
     * Get hierarchical display data similar to Claude Code's TeammateSpinnerTree.
     */
    public function getHierarchicalDisplay(): array
    {
        $display = [];
        
        // Group agents by team
        foreach ($this->teams as $teamName => $team) {
            $teamDisplay = [
                'type' => 'team',
                'name' => $teamName,
                'leaderId' => $team->getLeaderId(),
                'members' => [],
            ];
            
            // Add team members
            foreach ($team->getMembers() as $member) {
                if (isset($this->trackers[$member->agentId])) {
                    $progress = $this->trackers[$member->agentId]->getProgress();
                    $teamDisplay['members'][] = [
                        'agentId' => $member->agentId,
                        'name' => $member->name,
                        'status' => $member->status->value,
                        'currentActivity' => $progress['currentActivity'],
                        'tokenCount' => $progress['tokenCount'],
                        'toolUseCount' => $progress['toolUseCount'],
                    ];
                }
            }
            
            $display[] = $teamDisplay;
        }
        
        // Add standalone agents (not in teams)
        $standaloneAgents = [];
        foreach ($this->trackers as $agentId => $tracker) {
            $inTeam = false;
            foreach ($this->teams as $team) {
                if ($team->getMember($agentId)) {
                    $inTeam = true;
                    break;
                }
            }
            
            if (!$inTeam) {
                $progress = $tracker->getProgress();
                $standaloneAgents[] = [
                    'agentId' => $agentId,
                    'name' => $tracker->getAgentName(),
                    'status' => isset($this->runningTasks[$agentId]) 
                        ? $this->runningTasks[$agentId]->status->value 
                        : 'unknown',
                    'currentActivity' => $progress['currentActivity'],
                    'tokenCount' => $progress['tokenCount'],
                    'toolUseCount' => $progress['toolUseCount'],
                ];
            }
        }
        
        if (!empty($standaloneAgents)) {
            $display[] = [
                'type' => 'standalone',
                'name' => 'Standalone Agents',
                'members' => $standaloneAgents,
            ];
        }
        
        return $display;
    }
    
    /**
     * Reset the coordinator (for testing).
     */
    public static function reset(): void
    {
        if (self::$instance) {
            // Terminate all fibers
            foreach (self::$instance->fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    try {
                        $fiber->throw(new \Exception("Coordinator reset"));
                    } catch (\FiberError $e) {
                        // Ignore
                    }
                }
            }
        }
        
        self::$instance = null;
    }
}