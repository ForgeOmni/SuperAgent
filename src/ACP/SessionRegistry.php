<?php

declare(strict_types=1);

namespace SuperAgent\ACP;

/**
 * Maps ACP session ids → host-owned session state.
 *
 * The Handler implementation typically wraps each created internal session in
 * a SessionEntry (cwd, MCP config, the internal Agent instance, the current
 * model id) and stores it here so subsequent `session/prompt` / `session/cancel`
 * calls can look up state by the ACP-side id.
 *
 * Storage is in-memory and per-process; persistence (resume across restarts)
 * is the Handler's responsibility.
 */
final class SessionRegistry
{
    /** @var array<string, SessionEntry> */
    private array $entries = [];

    public function newId(string $prefix = 'acp_'): string
    {
        return $prefix . bin2hex(random_bytes(8));
    }

    public function put(SessionEntry $entry): void
    {
        $this->entries[$entry->id] = $entry;
    }

    public function get(string $id): ?SessionEntry
    {
        return $this->entries[$id] ?? null;
    }

    public function remove(string $id): void
    {
        unset($this->entries[$id]);
    }

    /** @return array<int, string> */
    public function ids(): array
    {
        return array_keys($this->entries);
    }
}
