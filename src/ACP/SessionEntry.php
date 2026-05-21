<?php

declare(strict_types=1);

namespace SuperAgent\ACP;

/**
 * Host-owned per-session state kept in {@see SessionRegistry}.
 *
 * `meta` is a free-form bag the host uses for whatever extra state it tracks
 * (model id, MCP server handles, the inner SuperAgent\Agent instance, ...).
 * The registry doesn't introspect it.
 */
final class SessionEntry
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $cwd,
        public array $meta = [],
    ) {
    }
}
