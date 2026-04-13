<?php

declare(strict_types=1);

namespace SuperAgent\Support;

/**
 * Lightweight Collection class for standalone (non-Laravel) usage.
 *
 * When illuminate/support is installed (Laravel), the framework's
 * collect() helper takes precedence and returns Illuminate\Support\Collection.
 * This class activates only when Laravel is absent.
 *
 * Implements the ~15 methods actually used across the SuperAgent codebase.
 */
class Collection implements \Countable, \IteratorAggregate, \ArrayAccess, \JsonSerializable
{
    protected array $items;

    public function __construct(array|self $items = [])
    {
        $this->items = $items instanceof self ? $items->all() : $items;
    }

    /** Get all items as a plain array. */
    public function all(): array
    {
        return $this->items;
    }

    /** Alias for all(). */
    public function toArray(): array
    {
        return array_map(function ($value) {
            if ($value instanceof self) {
                return $value->toArray();
            }
            if (is_object($value) && method_exists($value, 'toArray')) {
                return $value->toArray();
            }
            return $value;
        }, $this->items);
    }

    /** Check if a key exists. */
    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /** Get an item by key with optional default. */
    public function get(string|int $key, mixed $default = null): mixed
    {
        if ($this->has($key)) {
            return $this->items[$key];
        }

        return $default;
    }

    /** Set an item by key. */
    public function put(string|int $key, mixed $value): static
    {
        $this->items[$key] = $value;

        return $this;
    }

    /** Push an item onto the end. */
    public function push(mixed ...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    /** Run a callback over each item, returning a new collection. */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $values = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $values));
    }

    /** Filter items by a callback, returning a new collection. */
    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }

        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /** Filter items by the inverse of a callback. */
    public function reject(callable $callback): static
    {
        return $this->filter(function ($value, $key) use ($callback) {
            return ! $callback($value, $key);
        });
    }

    /** Run a callback over each item (no return). */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /** Get the first item, optionally matching a callback. */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : reset($this->items);
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /** Get the last item. */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : end($this->items);
        }

        return $this->filter($callback)->last(default: $default);
    }

    /** Reduce the collection to a single value. */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /** Get all keys. */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /** Get all values (re-indexed). */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /** Pluck a single field from each item. */
    public function pluck(string $field, ?string $key = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $value = is_array($item) ? ($item[$field] ?? null) : (is_object($item) ? ($item->$field ?? null) : null);

            if ($key !== null) {
                $keyValue = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null);
                $results[$keyValue] = $value;
            } else {
                $results[] = $value;
            }
        }

        return new static($results);
    }

    /** Check if the collection contains a value or matches a callback. */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        if (is_callable($key) && $operator === null) {
            foreach ($this->items as $k => $v) {
                if ($key($v, $k)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($key, $this->items, false);
    }

    /** Sort by a field or callback. */
    public function sortBy(string|callable $callback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $results = $this->items;

        if (is_string($callback)) {
            $field = $callback;
            $callback = function ($item) use ($field) {
                return is_array($item) ? ($item[$field] ?? null) : (is_object($item) ? ($item->$field ?? null) : null);
            };
        }

        uasort($results, function ($a, $b) use ($callback, $descending) {
            $aVal = $callback($a);
            $bVal = $callback($b);
            $result = $aVal <=> $bVal;
            return $descending ? -$result : $result;
        });

        return new static($results);
    }

    /** Sort by descending. */
    public function sortByDesc(string|callable $callback, int $options = SORT_REGULAR): static
    {
        return $this->sortBy($callback, $options, true);
    }

    /** Group items by a field or callback. */
    public function groupBy(string|callable $callback): static
    {
        $groups = [];

        foreach ($this->items as $key => $value) {
            $groupKey = is_callable($callback)
                ? $callback($value, $key)
                : (is_array($value) ? ($value[$callback] ?? '') : (is_object($value) ? ($value->$callback ?? '') : ''));

            $groups[$groupKey][] = $value;
        }

        return new static(array_map(fn ($group) => new static($group), $groups));
    }

    /** Flatten the collection. */
    public function flatten(int $depth = INF): static
    {
        $result = [];

        foreach ($this->items as $item) {
            if (($item instanceof self || is_array($item)) && $depth > 0) {
                $values = $item instanceof self ? $item->all() : $item;
                foreach ((new static($values))->flatten($depth - 1) as $value) {
                    $result[] = $value;
                }
            } else {
                $result[] = $item;
            }
        }

        return new static($result);
    }

    /** Get unique items. */
    public function unique(?callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $seen = [];
        $result = [];

        foreach ($this->items as $key => $item) {
            $id = $callback($item, $key);
            if (! in_array($id, $seen, true)) {
                $seen[] = $id;
                $result[$key] = $item;
            }
        }

        return new static($result);
    }

    /** Merge another array or collection. */
    public function merge(array|self $items): static
    {
        $other = $items instanceof self ? $items->all() : $items;

        return new static(array_merge($this->items, $other));
    }

    /** Sum of items or a callback. */
    public function sum(string|callable|null $callback = null): int|float
    {
        if ($callback === null) {
            return array_sum($this->items);
        }

        $total = 0;
        foreach ($this->items as $key => $value) {
            $total += is_callable($callback)
                ? $callback($value, $key)
                : (is_array($value) ? ($value[$callback] ?? 0) : (is_object($value) ? ($value->$callback ?? 0) : 0));
        }

        return $total;
    }

    /** Check if the collection is empty. */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /** Check if the collection is not empty. */
    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    // --- Interface implementations ---

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
