<?php

declare(strict_types=1);

namespace SuperAgent\Session\Contracts;

/**
 * Where session snapshots live.
 *
 * `SessionManager` has always written two copies of every session to local
 * disk: JSON files under its storage directory, and a SQLite database beside
 * them. That is the right default on a developer's machine and the wrong one
 * inside a multi-tenant product, where those files are other people's
 * conversations sitting on an application server — to be backed up, retained,
 * exported and deleted per tenant by a host that cannot reach them.
 *
 * A host that implements this interface and injects it keeps session snapshots
 * in its own storage, under its own retention rules. `SqliteSessionStorage` is
 * the bundled implementation and stays the default, so nothing changes for a
 * caller that injects nothing.
 *
 * A snapshot is the array `SessionManager` builds: session_id, cwd, model,
 * summary, messages, message_count, total_cost_usd, created_at, updated_at.
 *
 * @since 1.5.0
 */
interface SessionStore
{
    /** Insert or replace one session snapshot. */
    public function save(string $sessionId, array $snapshot): void;

    /** @return array<string,mixed>|null the snapshot, or null when unknown */
    public function load(string $sessionId): ?array;

    /** The most recently updated session, optionally scoped to one working directory. */
    public function loadLatest(?string $cwd = null): ?array;

    /** @return array<int,array<string,mixed>> summaries, newest first */
    public function listSessions(int $limit = 20, ?string $cwd = null): array;

    /**
     * Full-text search across stored messages. An implementation without a
     * search index returns an empty array rather than raising — the caller
     * treats "no results" and "no search" alike.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, int $limit = 10): array;

    public function delete(string $sessionId): bool;

    /** @return int how many sessions were removed */
    public function prune(int $maxSessions = 50, int $pruneAfterDays = 90, ?string $cwd = null): int;

    public function count(?string $cwd = null): int;
}
