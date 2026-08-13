<?php

declare(strict_types=1);

namespace SuperAgent\Sandbox;

use SuperAgent\Exceptions\SuperAgentException;

/**
 * Thrown when sandbox mode is `require` but no backend can enforce on this
 * host — fail-closed, never silently unconfined (landlock-run discipline:
 * "if the kernel can't enforce, exit without running the command").
 */
class SandboxUnavailableException extends SuperAgentException
{
}
