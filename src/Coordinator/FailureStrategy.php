<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

/**
 * Strategy for handling phase failures in a collaboration pipeline.
 */
enum FailureStrategy: string
{
    /** Stop the entire pipeline on first failure. */
    case FAIL_FAST = 'fail_fast';

    /** Log the failure and continue with remaining phases. */
    case CONTINUE = 'continue';

    /** Retry the failed phase up to the configured limit. */
    case RETRY = 'retry';

    /** Execute a designated fallback phase on failure. */
    case FALLBACK = 'fallback';
}
