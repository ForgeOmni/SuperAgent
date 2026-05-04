<?php

declare(strict_types=1);

namespace SuperAgent\Memory\VectorIndex;

/**
 * Linear-scan cosine similarity. Pure PHP, zero deps.
 *
 * Performance characteristics:
 *   - **Memory**: O(n × d) in floats stored once
 *   - **Insert**: O(d) per item (computes + caches the L2 norm so search
 *     skips re-normalising on every query)
 *   - **Search**: O(n × d) per query — for d=384, n=1000, this is
 *     ~400K float multiplies, ≈1ms on modern PHP.
 *
 * For the SuperAgent typical scale (a single session has tens-to-low-
 * hundreds of memories) this is faster than spawning a Node bridge, and
 * it never fails. HNSW becomes worth the bridge cost above ~10K items.
 *
 * Cosine math:
 *   cos(a, b) = (a · b) / (||a|| × ||b||)
 *
 * We pre-normalise stored vectors to unit length, so `cos(a, b)` reduces
 * to a plain dot product against an already-normalised query. This makes
 * search ~2× faster and the implementation auditable.
 */
final class BruteForceVectorIndex implements VectorIndex
{
    /**
     * @var array<string, array{vec: list<float>, payload: array<string, mixed>}>
     *   id => normalised vector + payload
     */
    private array $items = [];

    public function __construct(
        private readonly int $dimensions,
    ) {
        if ($dimensions <= 0) {
            throw new \InvalidArgumentException('dimensions must be > 0');
        }
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function add(string $id, array $vector, array $payload = []): void
    {
        if (count($vector) !== $this->dimensions) {
            throw new \InvalidArgumentException(
                "vector dim {$this->dimensions} expected, got " . count($vector)
            );
        }
        $normalised = self::normalise($vector);
        $this->items[$id] = ['vec' => $normalised, 'payload' => $payload];
    }

    public function addAll(iterable $items): void
    {
        foreach ($items as $item) {
            $this->add($item->id, $item->vector, $item->payload);
        }
    }

    public function remove(string $id): void
    {
        unset($this->items[$id]);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function search(array $query, int $k, float $minScore = 0.0): array
    {
        if ($k <= 0 || $this->items === []) return [];
        if (count($query) !== $this->dimensions) {
            throw new \InvalidArgumentException(
                "query dim {$this->dimensions} expected, got " . count($query)
            );
        }

        $normQuery = self::normalise($query);

        // Score every item, then top-k via heap-ish selection.
        $scored = [];
        foreach ($this->items as $id => $row) {
            $score = self::dot($normQuery, $row['vec']);
            if ($score < $minScore) continue;
            $scored[] = [$id, $score, $row['payload']];
        }

        usort($scored, fn ($a, $b) => $b[1] <=> $a[1]);
        $top = array_slice($scored, 0, $k);

        $out = [];
        foreach ($top as [$id, $score, $payload]) {
            $out[] = new SearchResult($id, $score, $payload);
        }
        return $out;
    }

    /**
     * L2-normalise a vector. Returns a copy of the input scaled so its
     * magnitude is 1.0. A zero vector is returned unchanged (its dot
     * product with anything is 0, which is the desired behavior — it
     * never wins a similarity ranking).
     *
     * @param  list<float> $v
     * @return list<float>
     */
    public static function normalise(array $v): array
    {
        $sumSq = 0.0;
        foreach ($v as $x) {
            $sumSq += $x * $x;
        }
        if ($sumSq === 0.0) return $v;
        $invMag = 1.0 / sqrt($sumSq);
        $out = [];
        foreach ($v as $x) {
            $out[] = $x * $invMag;
        }
        return $out;
    }

    /**
     * Dot product of two equal-length vectors. Inlined hot path —
     * called once per item per search query, so we avoid array_map /
     * iterator overhead.
     *
     * @param  list<float> $a
     * @param  list<float> $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $len = count($a);
        for ($i = 0; $i < $len; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }
}
