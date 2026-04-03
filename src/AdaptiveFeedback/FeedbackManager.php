<?php

declare(strict_types=1);

namespace SuperAgent\AdaptiveFeedback;

/**
 * High-level manager for the adaptive feedback system.
 *
 * Provides a unified interface for listing, viewing, deleting, clearing,
 * importing, and exporting correction patterns and promotions. This is
 * the API consumed by Artisan commands and skill handlers.
 *
 * Commands:
 *   feedback:list    — List all correction patterns with counts
 *   feedback:show    — Show details of a specific pattern
 *   feedback:delete  — Delete a specific pattern by ID
 *   feedback:clear   — Clear all correction patterns
 *   feedback:import  — Import patterns from a JSON file
 *   feedback:export  — Export all patterns to a JSON file
 *   feedback:promote — Force-promote a pattern to rule/memory
 *   feedback:stats   — Show statistics
 */
class FeedbackManager
{
    public function __construct(
        private readonly CorrectionStore $store,
        private readonly AdaptiveFeedbackEngine $engine,
        private readonly CorrectionCollector $collector,
    ) {}

    // ── List / View ────────────────────────────────────────────────

    /**
     * List all patterns with optional filtering.
     *
     * @return array{patterns: CorrectionPattern[], total: int, promoted: int, pending: int}
     */
    public function list(?CorrectionCategory $category = null, ?string $search = null): array
    {
        if ($search !== null) {
            $patterns = $this->store->search($search);
        } elseif ($category !== null) {
            $patterns = $this->store->getByCategory($category);
        } else {
            $patterns = $this->store->getAll();
        }

        // Sort by occurrences descending
        usort($patterns, fn (CorrectionPattern $a, CorrectionPattern $b) => $b->occurrences <=> $a->occurrences);

        $promoted = count(array_filter($patterns, fn (CorrectionPattern $p) => $p->promoted));

        return [
            'patterns' => $patterns,
            'total' => count($patterns),
            'promoted' => $promoted,
            'pending' => count($patterns) - $promoted,
        ];
    }

    /**
     * Show details of a specific pattern.
     */
    public function show(string $id): ?array
    {
        $pattern = $this->store->get($id);
        if ($pattern === null) {
            return null;
        }

        return [
            'pattern' => $pattern,
            'data' => $pattern->toArray(),
            'description' => $pattern->describe(),
            'promotable' => !$pattern->promoted && $pattern->occurrences >= 3,
        ];
    }

    // ── Delete / Clear ─────────────────────────────────────────────

    /**
     * Delete a specific pattern.
     */
    public function delete(string $id): bool
    {
        return $this->store->delete($id);
    }

    /**
     * Clear all patterns and return the count removed.
     */
    public function clear(): int
    {
        return $this->store->clear();
    }

    // ── Import / Export ────────────────────────────────────────────

    /**
     * Export all patterns to a JSON string.
     */
    public function export(): string
    {
        $data = $this->store->export();

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Export patterns to a file.
     */
    public function exportToFile(string $path): int
    {
        $json = $this->export();
        file_put_contents($path, $json);

        return count($this->store->getAll());
    }

    /**
     * Import patterns from a JSON string.
     *
     * @return int Number of patterns imported
     */
    public function import(string $json): int
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON format');
        }

        return $this->store->import($data);
    }

    /**
     * Import patterns from a file.
     *
     * @return int Number of patterns imported
     */
    public function importFromFile(string $path): int
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \InvalidArgumentException("Cannot read file: {$path}");
        }

        return $this->import($json);
    }

    // ── Promotion ──────────────────────────────────────────────────

    /**
     * Force-promote a specific pattern regardless of threshold.
     */
    public function promote(string $id): ?PromotionResult
    {
        return $this->engine->promoteById($id);
    }

    /**
     * Run automatic promotion for all eligible patterns.
     *
     * @return PromotionResult[]
     */
    public function autoPromote(): array
    {
        return $this->engine->evaluate();
    }

    /**
     * Get patterns that are close to being promoted.
     *
     * @return array{pattern: CorrectionPattern, remaining: int}[]
     */
    public function getSuggestions(): array
    {
        return $this->engine->getSuggestions();
    }

    // ── Recording (for programmatic use) ───────────────────────────

    /**
     * Record a manual correction.
     */
    public function recordCorrection(string $feedback, ?string $toolName = null): CorrectionPattern
    {
        return $this->collector->recordCorrection($feedback, $toolName);
    }

    // ── Statistics ─────────────────────────────────────────────────

    /**
     * Get comprehensive statistics.
     */
    public function getStatistics(): array
    {
        return $this->engine->getStatistics();
    }

    // ── Access sub-components ──────────────────────────────────────

    public function getStore(): CorrectionStore
    {
        return $this->store;
    }

    public function getEngine(): AdaptiveFeedbackEngine
    {
        return $this->engine;
    }

    public function getCollector(): CorrectionCollector
    {
        return $this->collector;
    }
}
