<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Context;

class RateTracker
{
    /**
     * Sliding window entries: key => [timestamp, ...]
     *
     * @var array<string, float[]>
     */
    private array $windows = [];

    /**
     * Record a call for the given key at the current time.
     */
    public function record(string $key): void
    {
        $this->windows[$key][] = microtime(true);
    }

    /**
     * Count calls within the last $windowSeconds for the given key.
     */
    public function countInWindow(string $key, int $windowSeconds): int
    {
        if (!isset($this->windows[$key])) {
            return 0;
        }

        $cutoff = microtime(true) - $windowSeconds;

        // Prune expired entries
        $this->windows[$key] = array_values(
            array_filter($this->windows[$key], fn (float $ts) => $ts >= $cutoff)
        );

        return count($this->windows[$key]);
    }

    /**
     * Check if the rate exceeds the given limit within the window.
     */
    public function exceedsRate(string $key, int $windowSeconds, int $maxCalls): bool
    {
        return $this->countInWindow($key, $windowSeconds) >= $maxCalls;
    }

    /**
     * Reset all tracked windows.
     */
    public function reset(): void
    {
        $this->windows = [];
    }

    /**
     * Reset a specific key.
     */
    public function resetKey(string $key): void
    {
        unset($this->windows[$key]);
    }
}
