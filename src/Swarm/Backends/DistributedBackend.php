<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Backends;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentSpawnResult;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\AgentTask;
use SuperAgent\Swarm\BackendType;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\TeamContext;

/**
 * Distributed backend for running agents across multiple machines/processes.
 * Uses message queuing and RPC for coordination.
 */
class DistributedBackend implements BackendInterface
{
    private array $nodes = [];
    private array $agents = [];
    private array $tasks = [];
    private LoggerInterface $logger;
    private ?TeamContext $teamContext = null;
    private ParallelAgentCoordinator $coordinator;
    private ?string $messageQueueUrl = null;
    private ?string $coordinatorUrl = null;
    
    public function __construct(
        ?LoggerInterface $logger = null,
        ?string $messageQueueUrl = null,
        ?string $coordinatorUrl = null,
        ?ParallelAgentCoordinator $coordinator = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance($this->logger);
        $this->messageQueueUrl = $messageQueueUrl ?? getenv('SUPERAGENT_MQ_URL') ?: 'redis://localhost:6379';
        $this->coordinatorUrl = $coordinatorUrl ?? getenv('SUPERAGENT_COORDINATOR_URL') ?: 'http://localhost:8080';
        
        $this->initializeNodes();
    }
    
    public function setTeamContext(TeamContext $context): void
    {
        $this->teamContext = $context;
        $this->coordinator->registerTeam($context);
    }
    
    public function getType(): BackendType
    {
        return BackendType::DISTRIBUTED;
    }
    
    public function isAvailable(): bool
    {
        // Check if at least one node is available
        return !empty($this->getAvailableNodes());
    }
    
    public function spawn(AgentSpawnConfig $config): AgentSpawnResult
    {
        try {
            // Select best node for this agent
            $node = $this->selectNode($config);
            if (!$node) {
                throw new \RuntimeException("No available nodes for agent spawn");
            }
            
            // Generate IDs
            $agentId = $this->generateAgentId($config->name);
            $taskId = uniqid('task_', true);
            
            $this->logger->debug("Spawning distributed agent", [
                'agent_id' => $agentId,
                'task_id' => $taskId,
                'name' => $config->name,
                'node' => $node['id'],
            ]);
            
            // Create task record
            $task = new AgentTask(
                taskId: $taskId,
                agentId: $agentId,
                agentName: $config->name,
                status: AgentStatus::PENDING,
                backend: BackendType::DISTRIBUTED,
                teamName: $config->teamName,
                startedAt: new \DateTimeImmutable(),
                metadata: ['node_id' => $node['id']],
            );
            $this->tasks[$taskId] = $task;
            
            // Register with coordinator
            $this->coordinator->registerAgent(
                $agentId,
                $config->name,
                $config->teamName,
                $task
            );
            
            // Send spawn request to node
            $spawnRequest = [
                'type' => 'spawn',
                'agent_id' => $agentId,
                'task_id' => $taskId,
                'config' => $config->toArray(),
                'timestamp' => microtime(true),
            ];
            
            $response = $this->sendToNode($node, $spawnRequest);
            
            if (!$response['success']) {
                throw new \RuntimeException($response['error'] ?? 'Failed to spawn on node');
            }
            
            // Track agent location
            $this->agents[$agentId] = [
                'node_id' => $node['id'],
                'task_id' => $taskId,
                'status' => AgentStatus::RUNNING,
            ];
            
            // Update task status
            $this->updateTaskStatus($taskId, AgentStatus::RUNNING);
            
            return new AgentSpawnResult(
                success: true,
                agentId: $agentId,
                taskId: $taskId,
                metadata: ['node' => $node['id']],
            );
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to spawn distributed agent", [
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
        
        $agent = $this->agents[$agentId];
        $node = $this->getNode($agent['node_id']);
        
        if (!$node) {
            throw new \RuntimeException("Node {$agent['node_id']} not available");
        }
        
        // Send message through message queue
        $this->publishMessage([
            'type' => 'agent_message',
            'agent_id' => $agentId,
            'message' => $message->toArray(),
            'node_id' => $agent['node_id'],
        ]);
        
        $this->logger->debug("Message sent to distributed agent", [
            'agent_id' => $agentId,
            'node' => $agent['node_id'],
        ]);
    }
    
    public function requestShutdown(string $agentId, ?string $reason = null): void
    {
        if (!isset($this->agents[$agentId])) {
            return;
        }
        
        $agent = $this->agents[$agentId];
        $node = $this->getNode($agent['node_id']);
        
        if ($node) {
            $this->sendToNode($node, [
                'type' => 'shutdown',
                'agent_id' => $agentId,
                'reason' => $reason,
            ]);
        }
        
        $this->updateTaskStatus($agent['task_id'], AgentStatus::CANCELLED);
        
        $this->logger->info("Shutdown requested for distributed agent", [
            'agent_id' => $agentId,
            'node' => $agent['node_id'],
            'reason' => $reason,
        ]);
    }
    
    public function kill(string $agentId): void
    {
        $this->requestShutdown($agentId, 'Force kill requested');
        $this->cleanup($agentId);
    }
    
    public function getStatus(string $agentId): ?AgentStatus
    {
        return $this->agents[$agentId]['status'] ?? null;
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
        
        // Remove agent records
        if (isset($this->agents[$agentId])) {
            $taskId = $this->agents[$agentId]['task_id'];
            unset($this->tasks[$taskId]);
            unset($this->agents[$agentId]);
        }
        
        $this->logger->debug("Cleaned up distributed agent", [
            'agent_id' => $agentId,
        ]);
    }
    
    /**
     * Register a compute node.
     */
    public function registerNode(array $nodeInfo): void
    {
        $nodeId = $nodeInfo['id'] ?? uniqid('node_', true);
        
        $this->nodes[$nodeId] = [
            'id' => $nodeId,
            'url' => $nodeInfo['url'],
            'capacity' => $nodeInfo['capacity'] ?? 10,
            'current_load' => 0,
            'status' => 'online',
            'last_heartbeat' => time(),
            'metadata' => $nodeInfo['metadata'] ?? [],
        ];
        
        $this->logger->info("Registered compute node", [
            'node_id' => $nodeId,
            'url' => $nodeInfo['url'],
        ]);
    }
    
    /**
     * Get node health status.
     */
    public function getNodeHealth(): array
    {
        $health = [];
        
        foreach ($this->nodes as $nodeId => $node) {
            $health[$nodeId] = [
                'id' => $nodeId,
                'status' => $node['status'],
                'load' => $node['current_load'],
                'capacity' => $node['capacity'],
                'utilization' => $node['capacity'] > 0 
                    ? ($node['current_load'] / $node['capacity']) * 100 
                    : 0,
                'last_heartbeat' => $node['last_heartbeat'],
                'healthy' => $this->isNodeHealthy($node),
            ];
        }
        
        return $health;
    }
    
    /**
     * Get distributed system statistics.
     */
    public function getDistributedStats(): array
    {
        $totalCapacity = 0;
        $totalLoad = 0;
        $onlineNodes = 0;
        
        foreach ($this->nodes as $node) {
            if ($node['status'] === 'online') {
                $onlineNodes++;
                $totalCapacity += $node['capacity'];
                $totalLoad += $node['current_load'];
            }
        }
        
        return [
            'total_nodes' => count($this->nodes),
            'online_nodes' => $onlineNodes,
            'total_capacity' => $totalCapacity,
            'total_load' => $totalLoad,
            'utilization_percent' => $totalCapacity > 0 
                ? ($totalLoad / $totalCapacity) * 100 
                : 0,
            'distributed_agents' => count($this->agents),
            'message_queue' => $this->messageQueueUrl,
            'coordinator' => $this->coordinatorUrl,
        ];
    }
    
    /**
     * Initialize nodes from configuration or discovery.
     */
    private function initializeNodes(): void
    {
        // Try to discover nodes from coordinator
        try {
            $nodes = $this->discoverNodes();
            foreach ($nodes as $node) {
                $this->registerNode($node);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to discover nodes", [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Register localhost as fallback
        if (empty($this->nodes)) {
            $this->registerNode([
                'id' => 'localhost',
                'url' => 'http://localhost:8081',
                'capacity' => 5,
            ]);
        }
    }
    
    /**
     * Discover available nodes from coordinator.
     */
    private function discoverNodes(): array
    {
        // In real implementation, would query coordinator service
        // For now, return empty array
        return [];
    }
    
    /**
     * Select best node for agent spawn.
     */
    private function selectNode(AgentSpawnConfig $config): ?array
    {
        $availableNodes = $this->getAvailableNodes();
        
        if (empty($availableNodes)) {
            return null;
        }
        
        // Simple load balancing: select node with lowest utilization
        usort($availableNodes, function($a, $b) {
            $utilizationA = $a['current_load'] / max($a['capacity'], 1);
            $utilizationB = $b['current_load'] / max($b['capacity'], 1);
            return $utilizationA <=> $utilizationB;
        });
        
        return $availableNodes[0];
    }
    
    /**
     * Get available nodes.
     */
    private function getAvailableNodes(): array
    {
        $available = [];
        
        foreach ($this->nodes as $node) {
            if ($this->isNodeHealthy($node) && $node['current_load'] < $node['capacity']) {
                $available[] = $node;
            }
        }
        
        return $available;
    }
    
    /**
     * Check if node is healthy.
     */
    private function isNodeHealthy(array $node): bool
    {
        // Node is healthy if online and heartbeat within last 30 seconds
        return $node['status'] === 'online' 
            && (time() - $node['last_heartbeat']) < 30;
    }
    
    /**
     * Get node by ID.
     */
    private function getNode(string $nodeId): ?array
    {
        return $this->nodes[$nodeId] ?? null;
    }
    
    /**
     * Send request to node.
     */
    private function sendToNode(array $node, array $request): array
    {
        // In real implementation, would use HTTP client or RPC
        // For now, simulate response
        return ['success' => true];
    }
    
    /**
     * Publish message to queue.
     */
    private function publishMessage(array $message): void
    {
        // In real implementation, would use Redis/RabbitMQ/etc
        // For now, just log
        $this->logger->debug("Publishing message to queue", $message);
    }
    
    /**
     * Update task status.
     */
    private function updateTaskStatus(string $taskId, AgentStatus $status): void
    {
        if (isset($this->tasks[$taskId])) {
            $task = $this->tasks[$taskId];
            $this->tasks[$taskId] = new AgentTask(
                taskId: $task->taskId,
                agentId: $task->agentId,
                agentName: $task->agentName,
                status: $status,
                backend: $task->backend,
                teamName: $task->teamName,
                startedAt: $task->startedAt,
                completedAt: in_array($status, [AgentStatus::COMPLETED, AgentStatus::FAILED, AgentStatus::CANCELLED])
                    ? new \DateTimeImmutable()
                    : null,
                metadata: $task->metadata,
            );
            
            // Update agent status
            if (isset($this->agents[$task->agentId])) {
                $this->agents[$task->agentId]['status'] = $status;
            }
        }
    }
    
    /**
     * Generate unique agent ID.
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
     * Handle heartbeat from nodes.
     */
    public function handleNodeHeartbeat(string $nodeId, array $stats = []): void
    {
        if (isset($this->nodes[$nodeId])) {
            $this->nodes[$nodeId]['last_heartbeat'] = time();
            $this->nodes[$nodeId]['current_load'] = $stats['load'] ?? 0;
            
            if ($stats['status'] ?? null) {
                $this->nodes[$nodeId]['status'] = $stats['status'];
            }
        }
    }
    
    /**
     * Get parallel progress from distributed system.
     */
    public function getParallelProgress(): array
    {
        return $this->coordinator->getConsolidatedProgress();
    }
    
    /**
     * Get hierarchical display of distributed agents.
     */
    public function getHierarchicalDisplay(): array
    {
        $display = $this->coordinator->getHierarchicalDisplay();
        
        // Add node information
        foreach ($display as &$group) {
            if (isset($group['members'])) {
                foreach ($group['members'] as &$member) {
                    if (isset($this->agents[$member['agentId']])) {
                        $member['node'] = $this->agents[$member['agentId']]['node_id'];
                    }
                }
            }
        }
        
        return $display;
    }
}