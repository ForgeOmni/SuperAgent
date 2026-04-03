<?php

declare(strict_types=1);

namespace SuperAgent\AdaptiveFeedback;

/**
 * Persistent storage for correction patterns.
 *
 * Stores patterns in a JSON file with full CRUD, search, import/export.
 *
 * Storage format:
 *   {
 *     "version": "1.0",
 *     "patterns": { "id": {...}, ... },
 *     "stats": { "total_corrections": 42, "total_promotions": 5 },
 *     "last_updated": "2026-04-03T10:30:00+00:00"
 *   }
 */
class CorrectionStore
{
    /** @var array<string, CorrectionPattern> */
    private array $patterns = [];

    private int $totalCorrections = 0;

    private int $totalPromotions = 0;

    public function __construct(private readonly ?string $storagePath = null)
    {
        $this->load();
    }

    /**
     * Record a correction, creating or updating the pattern.
     */
    public function record(
        CorrectionCategory $category,
        string $pattern,
        string $reason,
        ?string $toolName = null,
        ?string $toolInput = null,
        array $metadata = [],
    ): CorrectionPattern {
        $id = CorrectionPattern::generateId($category, $toolName, $pattern);

        if (isset($this->patterns[$id])) {
            $this->patterns[$id]->recordOccurrence($reason);
        } else {
            $this->patterns[$id] = new CorrectionPattern(
                id: $id,
                category: $category,
                pattern: $pattern,
                toolName: $toolName,
                toolInput: $toolInput,
                occurrences: 1,
                reasons: [$reason],
                metadata: $metadata,
            );
        }

        $this->totalCorrections++;
        $this->save();

        return $this->patterns[$id];
    }

    /**
     * Get a pattern by ID.
     */
    public function get(string $id): ?CorrectionPattern
    {
        return $this->patterns[$id] ?? null;
    }

    /**
     * Get all patterns.
     *
     * @return CorrectionPattern[]
     */
    public function getAll(): array
    {
        return array_values($this->patterns);
    }

    /**
     * Get patterns by category.
     *
     * @return CorrectionPattern[]
     */
    public function getByCategory(CorrectionCategory $category): array
    {
        return array_values(array_filter(
            $this->patterns,
            fn (CorrectionPattern $p) => $p->category === $category,
        ));
    }

    /**
     * Get patterns by tool name.
     *
     * @return CorrectionPattern[]
     */
    public function getByTool(string $toolName): array
    {
        return array_values(array_filter(
            $this->patterns,
            fn (CorrectionPattern $p) => $p->toolName === $toolName,
        ));
    }

    /**
     * Get patterns that meet the promotion threshold.
     *
     * @return CorrectionPattern[]
     */
    public function getPromotable(int $minOccurrences = 3): array
    {
        return array_values(array_filter(
            $this->patterns,
            fn (CorrectionPattern $p) => $p->occurrences >= $minOccurrences && !$p->promoted,
        ));
    }

    /**
     * Get patterns that have been promoted.
     *
     * @return CorrectionPattern[]
     */
    public function getPromoted(): array
    {
        return array_values(array_filter(
            $this->patterns,
            fn (CorrectionPattern $p) => $p->promoted,
        ));
    }

    /**
     * Search patterns by keyword.
     *
     * @return CorrectionPattern[]
     */
    public function search(string $keyword): array
    {
        $keyword = strtolower($keyword);

        return array_values(array_filter(
            $this->patterns,
            fn (CorrectionPattern $p) =>
                str_contains(strtolower($p->pattern), $keyword)
                || str_contains(strtolower($p->toolName ?? ''), $keyword)
                || str_contains(strtolower(implode(' ', $p->reasons)), $keyword),
        ));
    }

    /**
     * Delete a pattern by ID.
     */
    public function delete(string $id): bool
    {
        if (!isset($this->patterns[$id])) {
            return false;
        }

        unset($this->patterns[$id]);
        $this->save();

        return true;
    }

    /**
     * Clear all patterns.
     */
    public function clear(): int
    {
        $count = count($this->patterns);
        $this->patterns = [];
        $this->totalCorrections = 0;
        $this->totalPromotions = 0;
        $this->save();

        return $count;
    }

    /**
     * Mark a pattern as promoted.
     */
    public function markPromoted(string $id, string $type): void
    {
        if (isset($this->patterns[$id])) {
            $this->patterns[$id]->markPromoted($type);
            $this->totalPromotions++;
            $this->save();
        }
    }

    /**
     * Get statistics.
     *
     * @return array{total_patterns: int, total_corrections: int, total_promotions: int, by_category: array}
     */
    public function getStatistics(): array
    {
        $byCategory = [];
        foreach (CorrectionCategory::cases() as $cat) {
            $patterns = $this->getByCategory($cat);
            if (!empty($patterns)) {
                $byCategory[$cat->value] = count($patterns);
            }
        }

        return [
            'total_patterns' => count($this->patterns),
            'total_corrections' => $this->totalCorrections,
            'total_promotions' => $this->totalPromotions,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Export all patterns to a portable array.
     */
    public function export(): array
    {
        return [
            'version' => '1.0',
            'exported_at' => date('c'),
            'patterns' => array_map(fn (CorrectionPattern $p) => $p->toArray(), $this->patterns),
            'stats' => $this->getStatistics(),
        ];
    }

    /**
     * Import patterns from an exported array (merges with existing).
     *
     * @return int Number of patterns imported
     */
    public function import(array $data): int
    {
        $imported = 0;
        $patterns = $data['patterns'] ?? [];

        foreach ($patterns as $patternData) {
            $pattern = CorrectionPattern::fromArray($patternData);

            if (isset($this->patterns[$pattern->id])) {
                // Merge: take the higher occurrence count
                $existing = $this->patterns[$pattern->id];
                if ($pattern->occurrences > $existing->occurrences) {
                    $this->patterns[$pattern->id] = $pattern;
                    $imported++;
                }
            } else {
                $this->patterns[$pattern->id] = $pattern;
                $imported++;
            }
        }

        if ($imported > 0) {
            $this->save();
        }

        return $imported;
    }

    /**
     * Load from storage.
     */
    private function load(): void
    {
        if ($this->storagePath === null || !file_exists($this->storagePath)) {
            return;
        }

        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            return;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return;
        }

        foreach ($data['patterns'] ?? [] as $id => $patternData) {
            $this->patterns[$id] = CorrectionPattern::fromArray($patternData);
        }

        $this->totalCorrections = (int) ($data['stats']['total_corrections'] ?? 0);
        $this->totalPromotions = (int) ($data['stats']['total_promotions'] ?? 0);
    }

    /**
     * Save to storage.
     */
    private function save(): void
    {
        if ($this->storagePath === null) {
            return;
        }

        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'version' => '1.0',
            'patterns' => array_map(fn (CorrectionPattern $p) => $p->toArray(), $this->patterns),
            'stats' => [
                'total_corrections' => $this->totalCorrections,
                'total_promotions' => $this->totalPromotions,
            ],
            'last_updated' => date('c'),
        ];

        file_put_contents(
            $this->storagePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }
}
