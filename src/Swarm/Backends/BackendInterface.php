<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Backends;

use SuperAgent\Swarm\AgentMessage;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentSpawnResult;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\BackendType;

/**
 * Interface for agent execution backends.
 */
interface BackendInterface
{
    /**
     * Get the backend type.
     */
    public function getType(): BackendType;
    
    /**
     * Check if this backend is available on the current system.
     */
    public function isAvailable(): bool;
    
    /**
     * Spawn a new agent using this backend.
     */
    public function spawn(AgentSpawnConfig $config): AgentSpawnResult;
    
    /**
     * Send a message to an agent.
     */
    public function sendMessage(string $agentId, AgentMessage $message): void;
    
    /**
     * Request an agent to shutdown.
     */
    public function requestShutdown(string $agentId, ?string $reason = null): void;
    
    /**
     * Forcefully kill an agent.
     */
    public function kill(string $agentId): void;
    
    /**
     * Get the status of an agent.
     */
    public function getStatus(string $agentId): ?AgentStatus;
    
    /**
     * Check if an agent is still running.
     */
    public function isRunning(string $agentId): bool;
    
    /**
     * Clean up resources for an agent.
     */
    public function cleanup(string $agentId): void;
}