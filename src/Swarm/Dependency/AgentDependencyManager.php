<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Dependency;

use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\Backends\BackendInterface;

/**
 * Manages dependencies between agents.
 * Ensures agents are executed in the correct order based on dependencies.
 */
class AgentDependencyManager
{
    private ParallelAgentCoordinator $coordinator;
    private array $dependencies = [];
    private array $dependencyGraph = [];
    private array $executionOrder = [];
    private array $waitingAgents = [];
    
    public function __construct(?ParallelAgentCoordinator $coordinator = null)
    {
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance();
    }
    
    /**
     * Register agent dependencies.
     * 
     * @param string $agentId The agent that has dependencies
     * @param array $dependsOn Array of agent IDs this agent depends on
     */
    public function registerDependencies(string $agentId, array $dependsOn): void
    {
        $this->dependencies[$agentId] = $dependsOn;
        $this->rebuildDependencyGraph();
    }
    
    /**
     * Register a chain of agents to execute sequentially.
     */
    public function registerChain(array $agentIds): void
    {
        for ($i = 1; $i < count($agentIds); $i++) {
            $this->registerDependencies($agentIds[$i], [$agentIds[$i - 1]]);
        }
    }
    
    /**
     * Register agents to execute in parallel (no dependencies).
     */
    public function registerParallel(array $agentIds): void
    {
        foreach ($agentIds as $agentId) {
            if (!isset($this->dependencies[$agentId])) {
                $this->dependencies[$agentId] = [];
            }
        }
        $this->rebuildDependencyGraph();
    }
    
    /**
     * Check if an agent can start execution.
     */
    public function canExecute(string $agentId): bool
    {
        if (!isset($this->dependencies[$agentId])) {
            return true; // No dependencies
        }
        
        foreach ($this->dependencies[$agentId] as $dependencyId) {
            if (!$this->isDependencyComplete($dependencyId)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get agents ready to execute (all dependencies met).
     */
    public function getReadyAgents(): array
    {
        $ready = [];
        
        foreach ($this->waitingAgents as $agentId => $config) {
            if ($this->canExecute($agentId)) {
                $ready[$agentId] = $config;
            }
        }
        
        return $ready;
    }
    
    /**
     * Add agent to waiting queue.
     */
    public function addWaitingAgent(string $agentId, AgentSpawnConfig $config): void
    {
        $this->waitingAgents[$agentId] = $config;
    }
    
    /**
     * Remove agent from waiting queue.
     */
    public function removeWaitingAgent(string $agentId): void
    {
        unset($this->waitingAgents[$agentId]);
    }
    
    /**
     * Process waiting agents and spawn those ready to execute.
     */
    public function processWaitingAgents(BackendInterface $backend): array
    {
        $spawned = [];
        $ready = $this->getReadyAgents();
        
        foreach ($ready as $agentId => $config) {
            $result = $backend->spawn($config);
            if ($result->success) {
                $spawned[] = $agentId;
                $this->removeWaitingAgent($agentId);
            }
        }
        
        return $spawned;
    }
    
    /**
     * Get execution order based on dependencies (topological sort).
     */
    public function getExecutionOrder(): array
    {
        if (empty($this->executionOrder)) {
            $this->executionOrder = $this->topologicalSort();
        }
        
        return $this->executionOrder;
    }
    
    /**
     * Detect circular dependencies.
     */
    public function detectCircularDependencies(): array
    {
        $visited = [];
        $recursionStack = [];
        $cycles = [];
        
        foreach (array_keys($this->dependencies) as $node) {
            if (!isset($visited[$node])) {
                $this->detectCyclesUtil($node, $visited, $recursionStack, $cycles);
            }
        }
        
        return $cycles;
    }
    
    /**
     * Get dependency tree visualization.
     */
    public function getDependencyTree(): array
    {
        $tree = [];
        
        // Find root nodes (no dependencies)
        $roots = [];
        foreach ($this->dependencies as $agentId => $deps) {
            if (empty($deps)) {
                $roots[] = $agentId;
            }
        }
        
        // Build tree from roots
        foreach ($roots as $root) {
            $tree[$root] = $this->buildSubtree($root);
        }
        
        return $tree;
    }
    
    /**
     * Get dependency statistics.
     */
    public function getStatistics(): array
    {
        $totalDeps = 0;
        $maxDeps = 0;
        $depths = [];
        
        foreach ($this->dependencies as $agentId => $deps) {
            $count = count($deps);
            $totalDeps += $count;
            $maxDeps = max($maxDeps, $count);
            $depths[$agentId] = $this->calculateDepth($agentId);
        }
        
        return [
            'total_agents' => count($this->dependencies),
            'total_dependencies' => $totalDeps,
            'max_dependencies' => $maxDeps,
            'max_depth' => !empty($depths) ? max($depths) : 0,
            'circular_dependencies' => count($this->detectCircularDependencies()),
            'execution_stages' => $this->getExecutionStages(),
        ];
    }
    
    /**
     * Get execution stages (agents that can run in parallel).
     */
    public function getExecutionStages(): array
    {
        $stages = [];
        $processed = [];
        $remaining = array_keys($this->dependencies);
        
        while (!empty($remaining)) {
            $stage = [];
            
            foreach ($remaining as $agentId) {
                if ($this->canExecuteWithProcessed($agentId, $processed)) {
                    $stage[] = $agentId;
                }
            }
            
            if (empty($stage)) {
                // Circular dependency or error
                break;
            }
            
            $stages[] = $stage;
            $processed = array_merge($processed, $stage);
            $remaining = array_diff($remaining, $stage);
        }
        
        return $stages;
    }
    
    /**
     * Clear all dependencies.
     */
    public function clear(): void
    {
        $this->dependencies = [];
        $this->dependencyGraph = [];
        $this->executionOrder = [];
        $this->waitingAgents = [];
    }
    
    /**
     * Rebuild the dependency graph.
     */
    private function rebuildDependencyGraph(): void
    {
        $this->dependencyGraph = [];
        
        foreach ($this->dependencies as $agentId => $deps) {
            if (!isset($this->dependencyGraph[$agentId])) {
                $this->dependencyGraph[$agentId] = [
                    'depends_on' => [],
                    'required_by' => [],
                ];
            }
            
            $this->dependencyGraph[$agentId]['depends_on'] = $deps;
            
            foreach ($deps as $dep) {
                if (!isset($this->dependencyGraph[$dep])) {
                    $this->dependencyGraph[$dep] = [
                        'depends_on' => [],
                        'required_by' => [],
                    ];
                }
                $this->dependencyGraph[$dep]['required_by'][] = $agentId;
            }
        }
        
        // Clear cached execution order
        $this->executionOrder = [];
    }
    
    /**
     * Check if a dependency is complete.
     */
    private function isDependencyComplete(string $dependencyId): bool
    {
        // Check with coordinator if agent is completed
        $tracker = $this->coordinator->getTracker($dependencyId);
        if (!$tracker) {
            return false; // Agent doesn't exist yet
        }
        
        $progress = $tracker->getProgress();
        return $progress['completedAt'] !== null;
    }
    
    /**
     * Topological sort for execution order.
     */
    private function topologicalSort(): array
    {
        $inDegree = [];
        $queue = [];
        $result = [];
        
        // Calculate in-degree for each node
        foreach ($this->dependencies as $agentId => $deps) {
            if (!isset($inDegree[$agentId])) {
                $inDegree[$agentId] = 0;
            }
            
            foreach ($deps as $dep) {
                if (!isset($inDegree[$dep])) {
                    $inDegree[$dep] = 0;
                }
                $inDegree[$agentId]++;
            }
        }
        
        // Find nodes with 0 in-degree
        foreach ($inDegree as $agentId => $degree) {
            if ($degree === 0) {
                $queue[] = $agentId;
            }
        }
        
        // Process queue
        while (!empty($queue)) {
            $current = array_shift($queue);
            $result[] = $current;
            
            // Decrease in-degree of dependent nodes
            if (isset($this->dependencyGraph[$current]['required_by'])) {
                foreach ($this->dependencyGraph[$current]['required_by'] as $dependent) {
                    $inDegree[$dependent]--;
                    if ($inDegree[$dependent] === 0) {
                        $queue[] = $dependent;
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Utility for cycle detection.
     */
    private function detectCyclesUtil(
        string $node,
        array &$visited,
        array &$recursionStack,
        array &$cycles
    ): void {
        $visited[$node] = true;
        $recursionStack[$node] = true;
        
        if (isset($this->dependencies[$node])) {
            foreach ($this->dependencies[$node] as $dep) {
                if (!isset($visited[$dep])) {
                    $this->detectCyclesUtil($dep, $visited, $recursionStack, $cycles);
                } elseif (isset($recursionStack[$dep])) {
                    $cycles[] = [$node, $dep];
                }
            }
        }
        
        unset($recursionStack[$node]);
    }
    
    /**
     * Build subtree for visualization.
     */
    private function buildSubtree(string $agentId): array
    {
        $subtree = [];
        
        if (isset($this->dependencyGraph[$agentId]['required_by'])) {
            foreach ($this->dependencyGraph[$agentId]['required_by'] as $child) {
                $subtree[$child] = $this->buildSubtree($child);
            }
        }
        
        return $subtree;
    }
    
    /**
     * Calculate depth of an agent in the dependency tree.
     */
    private function calculateDepth(string $agentId): int
    {
        if (!isset($this->dependencies[$agentId]) || empty($this->dependencies[$agentId])) {
            return 0;
        }
        
        $maxDepth = 0;
        foreach ($this->dependencies[$agentId] as $dep) {
            $maxDepth = max($maxDepth, $this->calculateDepth($dep) + 1);
        }
        
        return $maxDepth;
    }
    
    /**
     * Check if agent can execute with given processed list.
     */
    private function canExecuteWithProcessed(string $agentId, array $processed): bool
    {
        if (!isset($this->dependencies[$agentId])) {
            return true;
        }
        
        foreach ($this->dependencies[$agentId] as $dep) {
            if (!in_array($dep, $processed)) {
                return false;
            }
        }
        
        return true;
    }
}