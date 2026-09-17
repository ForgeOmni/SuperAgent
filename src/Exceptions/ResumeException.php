<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * A resume was refused: an unknown ticket, one that has already been answered,
 * an expired envelope, or an envelope belonging to a different agent.
 *
 * Every one of these means the host's own bookkeeping and the envelope
 * disagree, so the SDK refuses rather than guessing which is right.
 *
 * @since 1.2.0
 */
class ResumeException extends SuperAgentException
{
}
