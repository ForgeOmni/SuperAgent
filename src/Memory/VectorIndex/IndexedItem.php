<?php

declare(strict_types=1);

namespace SuperAgent\Memory\VectorIndex;

/**
 * One row to put into a `VectorIndex`. Convenience value object so
 * `addAll()` callers don't manage parallel arrays.
 */
final class IndexedItem
{
    /**
     * @param string              $id
     * @param list<float>         $vector
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly array $vector,
        public readonly array $payload = [],
    ) {}
}
