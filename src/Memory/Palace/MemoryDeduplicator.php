<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

/**
 * Duplicate detection for drawers.
 *
 * Two gates:
 *   1. Exact-hash check — cheap, catches re-saves of identical content.
 *   2. Shingle-overlap check — catches near-duplicates (same paragraph
 *      with minor whitespace or timestamp changes) without needing
 *      embeddings. A 5-gram Jaccard >= $threshold counts as a duplicate.
 *
 * The dedup scope is intentionally room-local by default: we don't
 * collapse the same sentence if it appears in different rooms, because
 * context matters. Pass null for $wingSlug / $roomSlug to scan globally.
 */
class MemoryDeduplicator
{
    public function __construct(
        private readonly PalaceStorage $storage,
        private readonly float $threshold = 0.85,
        private readonly int $shingleSize = 5,
    ) {}

    /**
     * Find the existing drawer that duplicates $candidate, if any.
     */
    public function findDuplicate(
        Drawer $candidate,
        ?string $wingSlug = null,
        ?Hall $hall = null,
        ?string $roomSlug = null,
    ): ?Drawer {
        $wingSlug ??= $candidate->wingSlug;
        $hall ??= $candidate->hall;
        $roomSlug ??= $candidate->roomSlug;

        $candidateShingles = null;

        foreach ($this->storage->iterateDrawers($wingSlug, $hall, $roomSlug) as $existing) {
            if ($existing->id === $candidate->id) {
                continue;
            }
            if ($existing->contentHash !== '' && $existing->contentHash === $candidate->contentHash) {
                return $existing;
            }
            if ($candidateShingles === null) {
                $candidateShingles = $this->shingles($candidate->content);
                if (empty($candidateShingles)) {
                    return null;
                }
            }
            $existingShingles = $this->shingles($existing->content);
            if (empty($existingShingles)) {
                continue;
            }
            if ($this->jaccard($candidateShingles, $existingShingles) >= $this->threshold) {
                return $existing;
            }
        }

        return null;
    }

    public function isDuplicate(Drawer $candidate): bool
    {
        return $this->findDuplicate($candidate) !== null;
    }

    /**
     * @return array<string, bool> set of shingle strings (used as keys for fast lookup)
     */
    private function shingles(string $text): array
    {
        $text = strtolower(preg_replace('/\s+/', ' ', $text) ?? '');
        $tokens = preg_split('/\s+/', trim($text)) ?: [];
        if (count($tokens) < $this->shingleSize) {
            return count($tokens) > 0 ? [implode(' ', $tokens) => true] : [];
        }
        $out = [];
        for ($i = 0, $n = count($tokens) - $this->shingleSize + 1; $i < $n; $i++) {
            $shingle = implode(' ', array_slice($tokens, $i, $this->shingleSize));
            $out[$shingle] = true;
        }

        return $out;
    }

    /**
     * @param array<string, bool> $a
     * @param array<string, bool> $b
     */
    private function jaccard(array $a, array $b): float
    {
        $intersect = 0;
        foreach ($a as $k => $_) {
            if (isset($b[$k])) {
                $intersect++;
            }
        }
        $union = count($a) + count($b) - $intersect;

        return $union > 0 ? $intersect / $union : 0.0;
    }
}
