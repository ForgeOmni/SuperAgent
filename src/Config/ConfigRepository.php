<?php

declare(strict_types=1);

namespace SuperAgent\Config;

/**
 * Standalone configuration repository for non-Laravel usage.
 *
 * Provides dot-notation access to a nested config array, mirroring
 * Laravel's config() behavior. Used as the backing store for the
 * config() polyfill when Laravel is not present.
 */
class ConfigRepository implements \ArrayAccess
{
    private static ?self $instance = null;

    private array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Get a configuration value using dot notation.
     *
     * @param  string|null  $key  Dot-separated key (e.g. 'superagent.providers.anthropic.api_key')
     * @param  mixed  $default  Default value if key not found
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->items;
        }

        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a configuration value using dot notation.
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * Check if a configuration key exists.
     */
    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    /**
     * Get all configuration items.
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Merge configuration values (deep merge).
     */
    public function merge(array $items): void
    {
        $this->items = $this->deepMerge($this->items, $items);
    }

    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    // --- ArrayAccess ---

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $segments = explode('.', (string) $offset);
        $current = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                unset($current[$segment]);
            } else {
                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    return;
                }
                $current = &$current[$segment];
            }
        }
    }
}
