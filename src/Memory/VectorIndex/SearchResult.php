<?php

declare(strict_types=1);

namespace SuperAgent\Memory\VectorIndex;

/**
 * One hit from `VectorIndex::search()`. `score` is cosine similarity in
 * [-1.0, 1.0] (1.0 = identical, 0.0 = orthogonal, -1.0 = opposite).
 *
 * `payload` is the same metadata array passed at `add()` time — the
 * index doesn't interpret it, just round-trips it so callers can
 * resolve hits back to domain objects (Memory rows, Skill ids, …).
 */
final class SearchResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly float $score,
        public readonly array $payload = [],
    ) {}
}
