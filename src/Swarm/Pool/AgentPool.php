<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Pool;

use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\ParallelAgentCoordinator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Agent pooling and resource management.
 * Pre-spawns agents for faster task assignment and manages resource limits.
 */
class AgentPool
{
    private array $availableAgents = [];
    private array $busyAgents = [];
    private array $poolConfig = [];
    private BackendInterface $backend;
    private ParallelAgentCoordinator $coordinator;
    private LoggerInterface $logger;
    
    // Resource limits
    private int $maxAgents;
    private int $minIdleAgents;
    private int $maxIdleAgents;
    private float $maxMemoryMb;
    private float $maxCpuPercent;
    
    // Metrics
    private array $metrics = [
        'total_spawned' => 0,
        'total_recycled' => 0,
        'total_destroyed' => 0,
        'total_tasks_assigned' => 0,
        'avg_wait_time_ms' => 0,
        'peak_agents' => 0,
    ];
    
    public function __construct(
        BackendInterface $backend,
        array $config = [],
        ?ParallelAgentCoordinator $coordinator = null,
        ?LoggerInterface $logger = null
    ) {
        $this->backend = $backend;
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance();
        $this->logger = $logger ?? new NullLogger();
        
        // Configure limits
        $this->maxAgents = $config['max_agents'] ?? 50;
        $this->minIdleAgents = $config['min_idle_agents'] ?? 2;
        $this->maxIdleAgents = $config['max_idle_agents'] ?? 10;
        $this->maxMemoryMb = $config['max_memory_mb'] ?? 2048;
        $this->maxCpuPercent = $config['max_cpu_percent'] ?? 80;
        
        $this->poolConfig = $config;
        
        // Pre-warm the pool
        $this->warmPool();
    }
    
    /**
     * Get an available agent from the pool or spawn a new one.
     */
    public function acquire(string $type = 'general', array $requirements = []): ?PooledAgent
    {
        $startTime = microtime(true);
        
        // Check resource limits
        if (!$this->checkResourceLimits()) {
            $this->logger->warning("Resource limits exceeded, cannot acquire agent");
            return null;
        }
        
        // Try to find suitable agent in pool
        $agent = $this->findSuitableAgent($type, $requirements);
        
        if (!$agent) {
            // Spawn new agent if under limit
            if ($this->getTotalAgentCount() < $this->maxAgents) {
                $agent = $this->spawnAgent($type, $requirements);
            } else {
                // Wait for agent to become available or timeout
                $agent = $this->waitForAgent($type, $requirements, 5000);
            }
        }
        
        if ($agent) {
            // Move to busy pool
            $this->markBusy($agent);
            
            // Update metrics
            $this->metrics['total_tasks_assigned']++;
            $waitTime = (microtime(true) - $startTime) * 1000;
            $this->updateAverageWaitTime($waitTime);
            
            $this->logger->info("Acquired agent from pool", [
                'agent_id' => $agent->getAgentId(),
                'type' => $type,
                'wait_time_ms' => $waitTime,
            ]);
        }
        
        return $agent;
    }
    
    /**
     * Release an agent back to the pool.
     */
    public function release(PooledAgent $agent): void
    {
        $agentId = $agent->getAgentId();
        
        // Remove from busy pool
        unset($this->busyAgents[$agentId]);
        
        // Check if agent should be recycled or destroyed
        if ($this->shouldRecycle($agent)) {
            $this->recycleAgent($agent);
        } else if ($this->shouldDestroy($agent)) {
            $this->destroyAgent($agent);
        } else {
            // Return to available pool
            $this->availableAgents[$agentId] = $agent;
            $agent->markAvailable();
        }
        
        $this->logger->info("Released agent to pool", [
            'agent_id' => $agentId,
            'available_count' => count($this->availableAgents),
            'busy_count' => count($this->busyAgents),
        ]);
        
        // Maintain pool size
        $this->maintainPool();
    }
    
    /**
     * Pre-warm the pool with minimum idle agents.
     */
    public function warmPool(): void
    {
        $toSpawn = $this->minIdleAgents - count($this->availableAgents);
        
        for ($i = 0; $i < $toSpawn; $i++) {
            $agent = $this->spawnAgent('general');
            if ($agent) {
                $this->availableAgents[$agent->getAgentId()] = $agent;
            }
        }
        
        $this->logger->info("Warmed agent pool", [
            'spawned' => $toSpawn,
            'available' => count($this->availableAgents),
        ]);
    }
    
    /**
     * Get pool statistics.
     */
    public function getStatistics(): array
    {
        return array_merge($this->metrics, [
            'available_agents' => count($this->availableAgents),
            'busy_agents' => count($this->busyAgents),
            'total_agents' => $this->getTotalAgentCount(),
            'pool_utilization' => $this->getUtilization(),
            'memory_usage_mb' => $this->getCurrentMemoryUsage(),
            'cpu_usage_percent' => $this->getCurrentCpuUsage(),
        ]);
    }
    
    /**
     * Scale the pool up or down based on demand.
     */
    public function autoScale(): void
    {
        $utilization = $this->getUtilization();
        $available = count($this->availableAgents);
        
        // Scale up if high utilization
        if ($utilization > 0.8 && $available < $this->maxIdleAgents) {
            $toSpawn = min(
                $this->maxIdleAgents - $available,
                $this->maxAgents - $this->getTotalAgentCount()
            );
            
            for ($i = 0; $i < $toSpawn; $i++) {
                $agent = $this->spawnAgent('general');
                if ($agent) {
                    $this->availableAgents[$agent->getAgentId()] = $agent;
                }
            }
            
            $this->logger->info("Auto-scaled up", ['spawned' => $toSpawn]);
        }
        
        // Scale down if low utilization
        if ($utilization < 0.2 && $available > $this->minIdleAgents) {
            $toRemove = $available - $this->minIdleAgents;
            $removed = 0;
            
            foreach ($this->availableAgents as $agentId => $agent) {
                if ($removed >= $toRemove) break;
                
                $this->destroyAgent($agent);
                unset($this->availableAgents[$agentId]);
                $removed++;
            }
            
            $this->logger->info("Auto-scaled down", ['removed' => $removed]);
        }
    }
    
    /**
     * Shutdown the pool and clean up all agents.
     */
    public function shutdown(): void
    {
        $this->logger->info("Shutting down agent pool");
        
        // Destroy all agents
        foreach ($this->availableAgents as $agent) {
            $this->destroyAgent($agent);
        }
        
        foreach ($this->busyAgents as $agent) {
            $this->destroyAgent($agent);
        }
        
        $this->availableAgents = [];
        $this->busyAgents = [];
        
        $this->logger->info("Agent pool shutdown complete", [
            'total_spawned' => $this->metrics['total_spawned'],
            'total_tasks' => $this->metrics['total_tasks_assigned'],
        ]);
    }
    
    /**
     * Find a suitable agent from the available pool.
     */
    private function findSuitableAgent(string $type, array $requirements): ?PooledAgent
    {
        foreach ($this->availableAgents as $agentId => $agent) {
            if ($agent->matches($type, $requirements)) {
                unset($this->availableAgents[$agentId]);
                return $agent;
            }
        }
        
        return null;
    }
    
    /**
     * Spawn a new agent.
     */
    private function spawnAgent(string $type, array $requirements = []): ?PooledAgent
    {
        $config = new AgentSpawnConfig(
            name: "PoolAgent_{$type}_" . uniqid(),
            prompt: '',
            model: $requirements['model'] ?? null,
            maxTokens: $requirements['max_tokens'] ?? null,
        );
        
        $result = $this->backend->spawn($config);
        
        if (!$result->success) {
            return null;
        }
        
        $this->metrics['total_spawned']++;
        $this->metrics['peak_agents'] = max(
            $this->metrics['peak_agents'],
            $this->getTotalAgentCount()
        );
        
        return new PooledAgent(
            agentId: $result->agentId,
            type: $type,
            requirements: $requirements,
            backend: $this->backend
        );
    }
    
    /**
     * Wait for an agent to become available.
     */
    private function waitForAgent(string $type, array $requirements, int $timeoutMs): ?PooledAgent
    {
        $startTime = microtime(true) * 1000;
        
        while ((microtime(true) * 1000 - $startTime) < $timeoutMs) {
            // Check if any agent became available
            $agent = $this->findSuitableAgent($type, $requirements);
            if ($agent) {
                return $agent;
            }
            
            // Wait a bit before checking again
            usleep(100000); // 100ms
        }
        
        return null;
    }
    
    /**
     * Mark agent as busy.
     */
    private function markBusy(PooledAgent $agent): void
    {
        $agent->markBusy();
        $this->busyAgents[$agent->getAgentId()] = $agent;
    }
    
    /**
     * Check if agent should be recycled.
     */
    private function shouldRecycle(PooledAgent $agent): bool
    {
        // Recycle after certain number of uses or time
        $maxUses = $this->poolConfig['max_uses_before_recycle'] ?? 100;
        $maxAge = $this->poolConfig['max_age_seconds'] ?? 3600;
        
        return $agent->getUseCount() >= $maxUses || 
               $agent->getAgeSeconds() >= $maxAge;
    }
    
    /**
     * Check if agent should be destroyed.
     */
    private function shouldDestroy(PooledAgent $agent): bool
    {
        // Destroy if over idle limit or unhealthy
        $idleCount = count($this->availableAgents);
        
        return $idleCount >= $this->maxIdleAgents || 
               !$agent->isHealthy();
    }
    
    /**
     * Recycle an agent (clean up and reset).
     */
    private function recycleAgent(PooledAgent $agent): void
    {
        $agent->recycle();
        $this->availableAgents[$agent->getAgentId()] = $agent;
        $this->metrics['total_recycled']++;
        
        $this->logger->debug("Recycled agent", [
            'agent_id' => $agent->getAgentId(),
        ]);
    }
    
    /**
     * Destroy an agent.
     */
    private function destroyAgent(PooledAgent $agent): void
    {
        $agent->destroy();
        $this->backend->cleanup($agent->getAgentId());
        $this->metrics['total_destroyed']++;
        
        $this->logger->debug("Destroyed agent", [
            'agent_id' => $agent->getAgentId(),
        ]);
    }
    
    /**
     * Maintain pool size within configured limits.
     */
    private function maintainPool(): void
    {
        $available = count($this->availableAgents);
        
        // Ensure minimum idle agents
        if ($available < $this->minIdleAgents) {
            $this->warmPool();
        }
        
        // Remove excess idle agents
        if ($available > $this->maxIdleAgents) {
            $toRemove = $available - $this->maxIdleAgents;
            $removed = 0;
            
            foreach ($this->availableAgents as $agentId => $agent) {
                if ($removed >= $toRemove) break;
                
                $this->destroyAgent($agent);
                unset($this->availableAgents[$agentId]);
                $removed++;
            }
        }
    }
    
    /**
     * Check resource limits.
     */
    private function checkResourceLimits(): bool
    {
        $memoryUsage = $this->getCurrentMemoryUsage();
        $cpuUsage = $this->getCurrentCpuUsage();
        
        return $memoryUsage < $this->maxMemoryMb && 
               $cpuUsage < $this->maxCpuPercent;
    }
    
    /**
     * Get total agent count.
     */
    private function getTotalAgentCount(): int
    {
        return count($this->availableAgents) + count($this->busyAgents);
    }
    
    /**
     * Get pool utilization percentage.
     */
    private function getUtilization(): float
    {
        $total = $this->getTotalAgentCount();
        if ($total === 0) return 0;
        
        return count($this->busyAgents) / $total;
    }
    
    /**
     * Get current memory usage in MB.
     */
    private function getCurrentMemoryUsage(): float
    {
        return memory_get_usage(true) / 1048576;
    }
    
    /**
     * Get current CPU usage percentage.
     */
    private function getCurrentCpuUsage(): float
    {
        // Simplified - in production would use actual CPU metrics
        return sys_getloadavg()[0] * 10;
    }
    
    /**
     * Update average wait time metric.
     */
    private function updateAverageWaitTime(float $waitTimeMs): void
    {
        $count = $this->metrics['total_tasks_assigned'];
        $currentAvg = $this->metrics['avg_wait_time_ms'];
        
        // Calculate new average
        $this->metrics['avg_wait_time_ms'] = 
            (($currentAvg * ($count - 1)) + $waitTimeMs) / $count;
    }
}

/**
 * Represents a pooled agent.
 */
class PooledAgent
{
    private string $agentId;
    private string $type;
    private array $requirements;
    private BackendInterface $backend;
    private AgentStatus $status;
    private int $useCount = 0;
    private float $createdAt;
    private ?float $lastUsedAt = null;
    
    public function __construct(
        string $agentId,
        string $type,
        array $requirements,
        BackendInterface $backend
    ) {
        $this->agentId = $agentId;
        $this->type = $type;
        $this->requirements = $requirements;
        $this->backend = $backend;
        $this->status = AgentStatus::IDLE;
        $this->createdAt = microtime(true);
    }
    
    public function getAgentId(): string
    {
        return $this->agentId;
    }
    
    public function matches(string $type, array $requirements): bool
    {
        if ($this->type !== $type && $type !== 'any') {
            return false;
        }
        
        // Check requirements match
        foreach ($requirements as $key => $value) {
            if (!isset($this->requirements[$key]) || 
                $this->requirements[$key] !== $value) {
                return false;
            }
        }
        
        return $this->status === AgentStatus::IDLE;
    }
    
    public function markBusy(): void
    {
        $this->status = AgentStatus::RUNNING;
        $this->lastUsedAt = microtime(true);
        $this->useCount++;
    }
    
    public function markAvailable(): void
    {
        $this->status = AgentStatus::IDLE;
    }
    
    public function getUseCount(): int
    {
        return $this->useCount;
    }
    
    public function getAgeSeconds(): float
    {
        return microtime(true) - $this->createdAt;
    }
    
    public function isHealthy(): bool
    {
        return $this->backend->isRunning($this->agentId);
    }
    
    public function recycle(): void
    {
        // Clean up agent state
        $this->useCount = 0;
        $this->status = AgentStatus::IDLE;
    }
    
    public function destroy(): void
    {
        $this->backend->kill($this->agentId);
        $this->status = AgentStatus::TERMINATED;
    }
}