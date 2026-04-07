<?php

declare(strict_types=1);

namespace SuperAgent\Tools;

/**
 * Centralized injectable state container for built-in tools.
 *
 * Replaces scattered static properties across 14 tool classes with a single
 * injectable instance, enabling proper test isolation and Swarm-mode correctness.
 *
 * Each tool's state is stored in a named bucket.  Tools read/write their own
 * bucket via get()/set() using their tool name as the key.
 */
class ToolStateManager
{
    /** @var array<string, array<string, mixed>> toolName => state */
    private array $buckets = [];

    /** @var array<string, int> toolName => next auto-increment id */
    private array $counters = [];

    // ── Generic accessors ────────────────────────────────────────

    /**
     * Get a value from a tool's state bucket.
     */
    public function get(string $tool, string $key, mixed $default = null): mixed
    {
        return $this->buckets[$tool][$key] ?? $default;
    }

    /**
     * Set a value in a tool's state bucket.
     */
    public function set(string $tool, string $key, mixed $value): void
    {
        $this->buckets[$tool][$key] = $value;
    }

    /**
     * Check if a key exists in a tool's state bucket.
     */
    public function has(string $tool, string $key): bool
    {
        return isset($this->buckets[$tool][$key]);
    }

    /**
     * Get the entire state bucket for a tool.
     */
    public function getBucket(string $tool): array
    {
        return $this->buckets[$tool] ?? [];
    }

    /**
     * Replace the entire state bucket for a tool.
     */
    public function setBucket(string $tool, array $state): void
    {
        $this->buckets[$tool] = $state;
    }

    // ── Auto-increment IDs ───────────────────────────────────────

    /**
     * Get and increment the next ID for a tool (replaces static $nextId).
     */
    public function nextId(string $tool): int
    {
        if (!isset($this->counters[$tool])) {
            $this->counters[$tool] = 1;
        }
        return $this->counters[$tool]++;
    }

    // ── Collection helpers ───────────────────────────────────────

    /**
     * Push a value onto an array stored under a key.
     */
    public function push(string $tool, string $key, mixed $value): void
    {
        if (!isset($this->buckets[$tool][$key])) {
            $this->buckets[$tool][$key] = [];
        }
        $this->buckets[$tool][$key][] = $value;
    }

    /**
     * Set a value in an associative array stored under a key.
     */
    public function putIn(string $tool, string $key, string|int $index, mixed $value): void
    {
        if (!isset($this->buckets[$tool][$key])) {
            $this->buckets[$tool][$key] = [];
        }
        $this->buckets[$tool][$key][$index] = $value;
    }

    /**
     * Remove a value from an associative array stored under a key.
     */
    public function removeFrom(string $tool, string $key, string|int $index): void
    {
        unset($this->buckets[$tool][$key][$index]);
    }

    // ── Reset ────────────────────────────────────────────────────

    /**
     * Clear all state for a specific tool.
     */
    public function clearTool(string $tool): void
    {
        unset($this->buckets[$tool], $this->counters[$tool]);
    }

    /**
     * Clear all state for all tools.
     */
    public function clearAll(): void
    {
        $this->buckets = [];
        $this->counters = [];
    }
}
