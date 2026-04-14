<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Carbon\Carbon;

/**
 * Search drawers with structured filters + optional semantic scoring.
 *
 * Strategy (in priority order):
 *   1. If wingSlug/roomSlug filters are provided, scope drawer iteration
 *      to that subtree. This mirrors MemPalace's wing+room filtering,
 *      which accounted for their +34% R@10 gain vs unfiltered.
 *   2. Score each drawer with:
 *        base  = keyword overlap
 *        +     cosine(query_embedding, drawer_embedding) if both exist
 *        +     recency bonus (decays over 30 days)
 *        +     access-count bonus (log-scaled)
 *   3. Return top N sorted descending.
 *
 * Vector search is opt-in: pass an embedding callable at construct time
 * and drawers that were stored with embeddings will be scored semantically.
 */
class PalaceRetriever
{
    /** @var (callable(string): float[])|null */
    private $embedFn;

    public function __construct(
        private readonly PalaceStorage $storage,
        private readonly PalaceGraph $graph,
        ?callable $embedFn = null,
        private readonly float $keywordWeight = 1.0,
        private readonly float $vectorWeight = 2.0,
        private readonly float $recencyWeight = 0.5,
        private readonly float $accessWeight = 0.3,
    ) {
        $this->embedFn = $embedFn;
    }

    public function vectorEnabled(): bool
    {
        return $this->embedFn !== null;
    }

    /**
     * @param array{wing?: string, hall?: Hall, room?: string, follow_tunnels?: bool} $filters
     * @return array<int, array{drawer: Drawer, score: float, breakdown: array<string,float>}>
     */
    public function search(string $query, int $limit = 5, array $filters = []): array
    {
        $wing = $filters['wing'] ?? null;
        $hall = $filters['hall'] ?? null;
        $room = $filters['room'] ?? null;
        $followTunnels = (bool) ($filters['follow_tunnels'] ?? false);

        $queryVec = null;
        if ($this->embedFn !== null && trim($query) !== '') {
            try {
                $queryVec = ($this->embedFn)($query);
            } catch (\Throwable) {
                $queryVec = null;
            }
        }
        $keywords = $this->tokenize($query);

        $scored = [];
        foreach ($this->storage->iterateDrawers($wing, $hall, $room) as $drawer) {
            $breakdown = $this->scoreDrawer($drawer, $keywords, $queryVec);
            $total = array_sum($breakdown);
            if ($total <= 0.0) {
                continue;
            }
            $scored[] = ['drawer' => $drawer, 'score' => $total, 'breakdown' => $breakdown];
        }

        // Follow tunnels — if we scoped to one wing/room, also pull matching
        // rooms from tunneled wings at a slight penalty.
        if ($followTunnels && $wing !== null && $room !== null) {
            foreach ($this->graph->tunnelsFromRoom($wing, $room) as $tunnel) {
                $otherWing = $tunnel->fromWingSlug === $wing ? $tunnel->toWingSlug : $tunnel->fromWingSlug;
                $otherRoom = $tunnel->fromWingSlug === $wing ? $tunnel->toRoomSlug : $tunnel->fromRoomSlug;
                foreach ($this->storage->iterateDrawers($otherWing, null, $otherRoom) as $drawer) {
                    $breakdown = $this->scoreDrawer($drawer, $keywords, $queryVec);
                    $total = array_sum($breakdown) * 0.85; // tunnel penalty
                    if ($total <= 0.0) {
                        continue;
                    }
                    $breakdown['tunnel_penalty'] = $total - array_sum($breakdown);
                    $scored[] = ['drawer' => $drawer, 'score' => $total, 'breakdown' => $breakdown];
                }
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @param string[]       $keywords
     * @param float[]|null   $queryVec
     * @return array<string, float>
     */
    private function scoreDrawer(Drawer $drawer, array $keywords, ?array $queryVec): array
    {
        $breakdown = ['keyword' => 0.0, 'vector' => 0.0, 'recency' => 0.0, 'access' => 0.0];

        if (!empty($keywords)) {
            $haystack = strtolower($drawer->content);
            $hits = 0;
            foreach ($keywords as $kw) {
                $hits += substr_count($haystack, $kw);
            }
            if ($hits > 0) {
                $breakdown['keyword'] = $this->keywordWeight * log(1 + $hits);
            }
        }

        if ($queryVec !== null && $drawer->embedding !== null) {
            $cos = $this->cosine($queryVec, $drawer->embedding);
            if ($cos > 0.0) {
                $breakdown['vector'] = $this->vectorWeight * $cos;
            }
        }

        $ageDays = $drawer->createdAt->diffInDays(Carbon::now());
        $breakdown['recency'] = $this->recencyWeight * exp(-$ageDays / 30.0);

        if ($drawer->accessCount > 0) {
            $breakdown['access'] = $this->accessWeight * log(1 + $drawer->accessCount);
        }

        return $breakdown;
    }

    /** @return string[] */
    private function tokenize(string $query): array
    {
        $query = strtolower($query);
        $query = preg_replace('/[^a-z0-9\s-]/', ' ', $query) ?? '';
        $tokens = preg_split('/\s+/', trim($query)) ?: [];

        return array_values(array_filter($tokens, fn ($t) => strlen($t) >= 3));
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private function cosine(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
