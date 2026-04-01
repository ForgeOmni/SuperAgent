<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Represents the isolation mode for agent execution.
 */
enum IsolationMode: string
{
    case NONE = 'none';
    case WORKTREE = 'worktree';
    case CONTAINER = 'container';
}