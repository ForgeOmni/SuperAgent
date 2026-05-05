<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Contracts;

/**
 * Surface area shared by the memories CLI / dashboard / MCP server.
 *
 * Codex's `memories-mcp` exposes four operations — `list / read /
 * search / update` — with pagination and line-offset support so an
 * agent can drill into a long memory file without paying for the
 * whole document. This interface declares the same shape so a host
 * can wire its existing memory backend to an MCP server with one
 * adapter class.
 *
 * Why a separate interface from `MemoryProviderInterface`:
 *
 *   `MemoryProviderInterface` is loop-side — turn hooks, search,
 *   inject. It's the thing the agent calls during a turn.
 *
 *   `MemoriesAccessor` is admin-side — list, read, paginate, edit.
 *   It's the thing a UI / MCP server / `/memories` slash command
 *   calls. Different consumers, different shape.
 *
 * Hosts that already have a memory store usually only need to write
 * a thin adapter implementing this interface to get a CLI / MCP /
 * dashboard "for free".
 */
interface MemoriesAccessor
{
    /**
     * Paginated listing of memory entries.
     *
     * @param int    $cursor   0-based starting offset; pass back the
     *                         previous response's `next_cursor`.
     * @param int    $limit    max entries to return; clamp at 100.
     * @param bool   $shallow  when true, omit the body — return only
     *                         id / title / size. Default true so the
     *                         listing is cheap; codex's MCP defaults
     *                         the same way.
     *
     * @return array{
     *   entries: list<array{
     *     id: string,
     *     title: string,
     *     bytes: int,
     *     line_count: int,
     *     body?: string,
     *     metadata?: array<string, mixed>,
     *   }>,
     *   next_cursor: ?int,
     *   total: int,
     * }
     */
    public function list(int $cursor = 0, int $limit = 25, bool $shallow = true): array;

    /**
     * Read one memory entry, optionally a slice.
     *
     * @param string $id           entry id
     * @param int    $startLine    1-based; default 1 = beginning
     * @param int|null $maxLines   null = whole file
     *
     * @return array{
     *   id: string,
     *   title: string,
     *   line_count: int,
     *   start_line: int,
     *   end_line: int,
     *   body: string,
     * }|null  null when the id doesn't exist
     */
    public function read(string $id, int $startLine = 1, ?int $maxLines = null): ?array;

    /**
     * Multi-query search. Codex supports `queries` as an array so the
     * model can fan out one MCP call across N keywords; we mirror.
     *
     * @param list<string> $queries
     * @param int          $contextLines  lines of surrounding context
     *                                    to include with each hit
     *
     * @return array{
     *   matches: list<array{
     *     id: string,
     *     title: string,
     *     line: int,
     *     query: string,
     *     snippet: string,
     *   }>,
     *   next_cursor: ?int,
     * }
     */
    public function search(array $queries, int $contextLines = 2, int $cursor = 0, int $limit = 25): array;

    /**
     * Atomically replace one memory entry's body. Returns the new
     * snapshot. Caller is responsible for any UntrustedInput wrapping
     * before storage.
     */
    public function update(string $id, string $body, array $metadata = []): array;
}
