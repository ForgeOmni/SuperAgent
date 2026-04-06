<?php

declare(strict_types=1);

namespace SuperAgent\Performance;

use SuperAgent\Tools\ToolResult;

/**
 * Zero-copy optimization for local tools.
 *
 * For in-process tool execution, passes PHP objects directly instead of
 * serializing to JSON and back. Maintains a shared memory buffer that
 * tools can read/write directly, avoiding redundant string conversions.
 */
class LocalToolZeroCopy
{
    /** @var array<string, mixed> Shared data buffer between tools */
    private array $sharedBuffer = [];

    /** @var array<string, string> File content cache (path => content) */
    private array $fileCache = [];

    private int $maxCacheSize;
    private int $currentCacheBytes = 0;

    public function __construct(
        private bool $enabled = true,
        int $maxCacheSizeMb = 50,
    ) {
        $this->maxCacheSize = $maxCacheSizeMb * 1024 * 1024;
    }

    public static function fromConfig(): self
    {
        try {
            $config = function_exists('config')
                ? (config('superagent.performance.local_tool_zero_copy') ?? [])
                : [];
        } catch (\Throwable $e) {
            error_log('[SuperAgent] Config unavailable for ' . static::class . ': ' . $e->getMessage());
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxCacheSizeMb: (int) ($config['max_cache_size_mb'] ?? 50),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Store a value in the shared buffer (avoids re-reading for the next tool).
     *
     * Example: After Read tool reads a file, store the content so Edit tool
     * can access it directly without re-reading from disk.
     */
    public function put(string $key, mixed $value): void
    {
        $this->sharedBuffer[$key] = $value;
    }

    /**
     * Get a value from the shared buffer.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->sharedBuffer[$key] ?? $default;
    }

    /**
     * Check if a key exists in the shared buffer.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->sharedBuffer);
    }

    /**
     * Cache file content for zero-copy access between Read/Edit/Write tools.
     */
    public function cacheFile(string $path, string $content): void
    {
        $size = strlen($content);

        // Evict if needed
        while ($this->currentCacheBytes + $size > $this->maxCacheSize && !empty($this->fileCache)) {
            $evictKey = array_key_first($this->fileCache);
            $this->currentCacheBytes -= strlen($this->fileCache[$evictKey]);
            unset($this->fileCache[$evictKey]);
        }

        if ($size <= $this->maxCacheSize) {
            $this->fileCache[$path] = $content;
            $this->currentCacheBytes += $size;
        }
    }

    /**
     * Get cached file content. Returns null if not cached or stale.
     * Checks file mtime to detect external modifications.
     */
    public function getCachedFile(string $path): ?string
    {
        if (!isset($this->fileCache[$path])) {
            return null;
        }

        // Invalidate if file was modified externally
        if (file_exists($path)) {
            $currentHash = md5_file($path);
            $cachedHash = md5($this->fileCache[$path]);
            if ($currentHash !== $cachedHash) {
                $this->invalidateFile($path);
                return null;
            }
        }

        return $this->fileCache[$path];
    }

    /**
     * Invalidate cached file content (e.g., after Write/Edit).
     */
    public function invalidateFile(string $path): void
    {
        if (isset($this->fileCache[$path])) {
            $this->currentCacheBytes -= strlen($this->fileCache[$path]);
            unset($this->fileCache[$path]);
        }
    }

    /**
     * Wrap a ToolResult to pass through the shared buffer reference.
     * The next tool in the pipeline can check the buffer before doing I/O.
     */
    public function wrapResult(string $toolName, array $toolInput, ToolResult $result): ToolResult
    {
        // After Read: cache the file content
        if ($toolName === 'read' && !$result->isError && isset($toolInput['file_path'])) {
            $this->cacheFile($toolInput['file_path'], $result->contentAsString());
        }

        // After Write/Edit: invalidate cache
        if (in_array($toolName, ['write', 'edit'], true) && isset($toolInput['file_path'])) {
            $this->invalidateFile($toolInput['file_path']);
        }

        return $result;
    }

    /**
     * Get cache statistics.
     */
    public function stats(): array
    {
        return [
            'buffer_keys' => count($this->sharedBuffer),
            'cached_files' => count($this->fileCache),
            'cache_bytes' => $this->currentCacheBytes,
            'max_cache_bytes' => $this->maxCacheSize,
        ];
    }

    /**
     * Clear all caches.
     */
    public function clear(): void
    {
        $this->sharedBuffer = [];
        $this->fileCache = [];
        $this->currentCacheBytes = 0;
    }
}
