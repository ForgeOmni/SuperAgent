<?php

declare(strict_types=1);

namespace SuperAgent\Goals\Contracts;

use SuperAgent\Goals\Goal;
use SuperAgent\Goals\GoalStatus;

/**
 * Persistence SPI for thread goals. The SDK ships an in-memory
 * implementation (`InMemoryGoalStore`); SuperAICore provides a
 * Eloquent-backed one (`EloquentGoalStore`) that survives process
 * restarts — that's the whole point of the SPI.
 *
 * Keep the interface thin so a host with a non-relational backend
 * (Redis, KV) can implement it without ceremony.
 */
interface GoalStore
{
    /** Insert a fresh goal. Throws if the thread already has an active one. */
    public function create(string $threadId, string $objective, ?int $tokenBudget): Goal;

    /** Latest goal for the thread, or null when none exists. */
    public function findActive(string $threadId): ?Goal;

    /** Lookup by goal id (used by tool handlers / restore paths). */
    public function findById(string $id): ?Goal;

    /** Replace status. Returns the new snapshot, or null if missing. */
    public function transition(string $id, GoalStatus $status): ?Goal;

    /** Increment tokens used. Returns the new snapshot. */
    public function recordTokens(string $id, int $tokensUsed): ?Goal;
}
