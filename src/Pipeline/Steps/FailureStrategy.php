<?php

declare(strict_types=1);

namespace SuperAgent\Pipeline\Steps;

/**
 * How to handle step failure.
 */
enum FailureStrategy: string
{
    /** Abort the entire pipeline immediately. */
    case ABORT = 'abort';

    /** Log the failure and continue to the next step. */
    case CONTINUE = 'continue';

    /** Retry the step up to max_retries times. */
    case RETRY = 'retry';
}
