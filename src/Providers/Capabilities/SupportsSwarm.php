<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

use SuperAgent\Providers\AsyncCapable;
use SuperAgent\Providers\JobHandle;

/**
 * Provider exposes a multi-agent "swarm" — a single prompt is decomposed
 * into many coordinated sub-agents executed in parallel (Kimi K2.6 Agent
 * Swarm with 300 sub-agents / 4000 steps; conceptually extends to MiniMax
 * M2.7 Agent Teams when wrapped).
 *
 * Always async: a swarm run routinely takes minutes, so `submitSwarm()`
 * returns a `JobHandle` and callers poll → fetch via `AsyncCapable`.
 *
 * `fetch()` returns the assembled swarm deliverable — the exact shape is
 * provider-specific but is typed loosely here. Provider docs are the
 * authoritative source.
 */
interface SupportsSwarm extends AsyncCapable
{
    /**
     * Submit a swarm job.
     *
     * @param array<string, mixed> $opts Provider knobs — sub-agent budget,
     *                                    step limit, deliverable format, …
     */
    public function submitSwarm(string $prompt, array $opts = []): JobHandle;
}
