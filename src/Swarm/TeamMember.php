<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Team member information.
 */
class TeamMember
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $name,
        public readonly BackendType $backend,
        public readonly ?string $color = null,
        public readonly ?int $pid = null,
        public readonly ?string $taskId = null,
        public readonly AgentStatus $status = AgentStatus::PENDING,
    ) {}
    
    public function toArray(): array
    {
        return array_filter([
            'agent_id' => $this->agentId,
            'name' => $this->name,
            'backend' => $this->backend->value,
            'color' => $this->color,
            'pid' => $this->pid,
            'task_id' => $this->taskId,
            'status' => $this->status->value,
        ], fn($v) => $v !== null);
    }
    
    public static function fromArray(array $data): self
    {
        return new self(
            agentId: $data['agent_id'],
            name: $data['name'],
            backend: BackendType::from($data['backend']),
            color: $data['color'] ?? null,
            pid: $data['pid'] ?? null,
            taskId: $data['task_id'] ?? null,
            status: AgentStatus::from($data['status'] ?? AgentStatus::PENDING->value),
        );
    }
}