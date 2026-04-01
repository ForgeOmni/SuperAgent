<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Represents the status of an agent task.
 */
enum AgentStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case PAUSED = 'paused';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}