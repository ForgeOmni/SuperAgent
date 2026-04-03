<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline;

/**
 * Represents the execution status of a pipeline step.
 */
enum StepStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case WAITING_APPROVAL = 'waiting_approval';
    case CANCELLED = 'cancelled';
}
