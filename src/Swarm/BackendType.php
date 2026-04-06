<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Represents the type of backend used to execute agents.
 */
enum BackendType: string
{
    case IN_PROCESS = 'in-process';
    case PROCESS = 'process';
    case TMUX = 'tmux';
    case DOCKER = 'docker';
    case REMOTE = 'remote';
}