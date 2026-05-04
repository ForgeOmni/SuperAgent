<?php

declare(strict_types=1);

namespace SuperAgent\Memory\VectorIndex;

/**
 * Storage + search abstraction for embedding vectors.
 *
 * Two backends ship out-of-the-box:
 *
 *   - `BruteForceVectorIndex` — pure-PHP cosine similarity over an
 *     in-process dict. Sub-millisecond for ≤1000 items. Zero deps.
 *   - `HnswVectorIndex` — JSON-RPC bridge to a Node subprocess running
 *     `@ruvector/rvf-wasm`'s HNSW index. ~150× faster at 10K+ items.
 *     Falls back to brute force when the bridge fails or Node isn't
 *     available, so calling code never has to branch on backend.
 *
 * Choose via `VectorIndexFactory::create()`. Hosts that want to plug in
 * sqlite-vss / Qdrant / a hosted vector DB just implement this interface
 * and register their adapter.
 *
 * **Vector dimension.** Set at construction time — every `add()` /
 * `search()` MUST use the same dimension. Mixing dimensions is undefined
 * behavior; backends free to throw, return 0 results, or panic at their
 * discretion.
 */
interface VectorIndex
{
    /** Number of indexed items. */
    public function count(): int;

    /** Vector dimension this index is configured for. */
    public function dimensions(): int;

    /**
     * Insert or replace an item. Replacing by id is idempotent —
     * same id with the same vector + payload is a no-op.
     *
     * @param string             $id
     * @param list<float>        $vector
     * @param array<string,mixed> $payload Caller-controlled metadata
     *                                     (Memory id, type, scope, …);
     *                                     returned verbatim on search hits.
     */
    public function add(string $id, array $vector, array $payload = []): void;

    /**
     * Bulk-insert. Implementations SHOULD optimise (batched HNSW build,
     * single SQL upsert, …) but the default `foreach($items as $i) add($i)`
     * is always semantically correct.
     *
     * @param iterable<IndexedItem> $items
     */
    public function addAll(iterable $items): void;

    /** Remove an item. No-op if id absent. */
    public function remove(string $id): void;

    /** Drop everything. */
    public function clear(): void;

    /**
     * Top-k cosine-similarity search.
     *
     * @param  list<float> $query Query vector — same dimension as the index.
     * @param  int         $k     Max results (0 < k ≤ count()).
     * @param  float       $minScore Skip results with cosine < this.
     *                              Default 0.0 means "rank everything,
     *                              return whatever's most similar".
     * @return list<SearchResult> in descending score order.
     */
    public function search(array $query, int $k, float $minScore = 0.0): array;
}
