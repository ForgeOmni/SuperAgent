<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Agent task information.
 */
class AgentTask
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $agentId,
        public readonly string $agentName,
        public readonly AgentStatus $status,
        public readonly BackendType $backend,
        public readonly ?string $teamName = null,
        public readonly ?int $pid = null,
        public readonly ?string $worktreePath = null,
        public readonly ?\DateTimeInterface $startedAt = null,
        public readonly ?\DateTimeInterface $completedAt = null,
        public readonly ?string $error = null,
        public readonly array $metadata = [],
    ) {}
    
    public function isActive(): bool
    {
        return in_array($this->status, [
            AgentStatus::PENDING,
            AgentStatus::RUNNING,
            AgentStatus::PAUSED,
        ]);
    }
    
    public function isCompleted(): bool
    {
        return in_array($this->status, [
            AgentStatus::COMPLETED,
            AgentStatus::FAILED,
            AgentStatus::CANCELLED,
        ]);
    }
}