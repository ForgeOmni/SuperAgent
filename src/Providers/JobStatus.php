<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Lifecycle states for an asynchronous provider job — submit → poll → fetch.
 *
 * The set is intentionally small: each state maps cleanly onto what upstream
 * APIs (Kimi Agent Swarm, MiniMax video generation, batch jobs, long-form TTS)
 * actually expose. Richer state machines (e.g. "queued vs. running") belong in
 * provider-specific subtypes, not here.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Done, self::Failed, self::Canceled => true,
            self::Pending, self::Running => false,
        };
    }
}
