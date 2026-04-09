<?php

declare(strict_types=1);

namespace SuperAgent\Tools;

/**
 * Per-tool result cache with TTL support.
 *
 * Caches tool execution results keyed by tool name + input hash.
 * Only read-only tools should enable caching. Cache entries expire
 * after their TTL to prevent stale results.
 */
class ToolResultCache
{
    /** @var array<string, array{result: ToolResult, expires: float}> */
    private array $cache = [];
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(
        private int $defaultTtlSeconds = 300,
        private int $maxEntries = 1000,
    ) {}

    /**
     * Get a cached result for the given tool call.
     */
    public function get(string $toolName, array $input): ?ToolResult
    {
        $key = $this->makeKey($toolName, $input);

        if (!isset($this->cache[$key])) {
            $this->misses++;
            return null;
        }

        $entry = $this->cache[$key];

        // Check TTL
        if (microtime(true) > $entry['expires']) {
            unset($this->cache[$key]);
            $this->misses++;
            return null;
        }

        $this->hits++;
        return $entry['result'];
    }

    /**
     * Store a result in the cache.
     *
     * @param int|null $ttl Override default TTL for this entry
     */
    public function set(string $toolName, array $input, ToolResult $result, ?int $ttl = null): void
    {
        // Don't cache errors
        if ($result->isError) {
            return;
        }

        // Evict oldest if at capacity
        if (count($this->cache) >= $this->maxEntries) {
            $this->evictOldest();
        }

        $key = $this->makeKey($toolName, $input);
        $this->cache[$key] = [
            'result' => $result,
            'expires' => microtime(true) + ($ttl ?? $this->defaultTtlSeconds),
        ];
    }

    /**
     * Invalidate cache for a specific tool.
     */
    public function invalidate(string $toolName): void
    {
        $prefix = $toolName . ':';
        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Invalidate all cache entries matching a file path.
     * Used when a write tool modifies a file that read tools may have cached.
     */
    public function invalidateByPath(string $path): void
    {
        foreach ($this->cache as $key => $entry) {
            // Check if the cached input references this path
            if (str_contains($key, md5($path))) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Clear the entire cache.
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Get cache statistics.
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        return [
            'entries' => count($this->cache),
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => $total > 0 ? round($this->hits / $total, 3) : 0.0,
        ];
    }

    private function makeKey(string $toolName, array $input): string
    {
        // Stable key: tool name + hash of sorted input
        ksort($input);
        return $toolName . ':' . md5(json_encode($input));
    }

    private function evictOldest(): void
    {
        $oldestKey = null;
        $oldestExpires = PHP_FLOAT_MAX;

        foreach ($this->cache as $key => $entry) {
            if ($entry['expires'] < $oldestExpires) {
                $oldestExpires = $entry['expires'];
                $oldestKey = $key;
            }
        }

        if ($oldestKey !== null) {
            unset($this->cache[$oldestKey]);
        }
    }
}
