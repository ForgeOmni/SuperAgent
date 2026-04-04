<?php

namespace SuperAgent\LazyContext;

/**
 * Simple TTL-based in-memory cache for loaded context fragments.
 */
class ContextCache
{
    private array $store = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'cache_ttl' => 600, // seconds
        ], $config);
    }

    public function get(string $id): ?array
    {
        if (!isset($this->store[$id])) {
            return null;
        }

        $entry = $this->store[$id];

        if (time() > $entry['expires_at']) {
            unset($this->store[$id]);
            return null;
        }

        return $entry['data'];
    }

    public function set(string $id, array $data): void
    {
        $this->store[$id] = [
            'data' => $data,
            'expires_at' => time() + $this->config['cache_ttl'],
        ];
    }

    public function delete(string $id): void
    {
        unset($this->store[$id]);
    }

    public function clear(): void
    {
        $this->store = [];
    }

    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }
}
