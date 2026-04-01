<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Result of spawning an agent.
 */
class AgentSpawnResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $agentId,
        public readonly ?string $taskId = null,
        public readonly ?string $error = null,
        public readonly ?int $pid = null,
        public readonly ?string $worktreePath = null,
    ) {}
}