<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Raised when sub-agent spawning would exceed the configured depth
 * cap. Same semantics as codex's recursion guard — kept as a distinct
 * exception class so hosts can catch it and degrade gracefully (e.g.
 * surface a "too many nested agents" tool result instead of bubbling
 * up as a generic error).
 */
class AgentDepthExceededException extends \RuntimeException
{
}
