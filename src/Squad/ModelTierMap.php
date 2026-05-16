<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use SuperAgent\Evals\ScoreCatalog;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Maps a `DifficultyClass` to a concrete (provider, model) pair.
 *
 * Defaults are deliberately cross-vendor — the whole point of the
 * Squad mode is that a complex workflow doesn't pay Opus prices for
 * a TRIVIAL subtask just because the *overall* task happens to be
 * hard. Each band routes to the cheapest model that history shows is
 * reliable at that band:
 *
 *   TRIVIAL   → Anthropic Haiku        (cheap, fast, accurate enough)
 *   EASY      → DeepSeek V4-Flash      (cheap, good code edits)
 *   MODERATE  → Anthropic Sonnet       (balanced)
 *   HARD      → DeepSeek V4-Pro        (deep reasoning, lower $ than Opus)
 *   EXPERT    → Anthropic Opus         (best reasoning, willing to pay)
 *
 * Callers can override the whole map via constructor or per-band
 * via `with()`. The map is immutable — `with()` returns a new copy.
 */
final class ModelTierMap
{
    /** @var array<string, array{provider: string, model: string}> */
    private array $map;

    /**
     * @param array<string, array{provider: string, model: string}> $map
     *        Keyed by `DifficultyClass::value`. Missing bands fall
     *        back to the defaults.
     */
    public function __construct(array $map = [])
    {
        $this->map = array_replace(self::defaults(), $map);
    }

    /**
     * Default cross-vendor band → (provider, model) map.
     *
     * @return array<string, array{provider: string, model: string}>
     */
    public static function defaults(): array
    {
        return [
            DifficultyClass::TRIVIAL->value  => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001'],
            DifficultyClass::EASY->value     => ['provider' => 'deepseek',  'model' => 'deepseek-v4-flash'],
            DifficultyClass::MODERATE->value => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
            DifficultyClass::HARD->value     => ['provider' => 'deepseek',  'model' => 'deepseek-v4-pro'],
            DifficultyClass::EXPERT->value   => ['provider' => 'anthropic', 'model' => 'claude-opus-4-7'],
        ];
    }

    /**
     * Return a copy with one band remapped.
     */
    public function with(DifficultyClass $band, string $provider, string $model): self
    {
        $next = clone $this;
        $next->map[$band->value] = ['provider' => $provider, 'model' => $model];
        return $next;
    }

    /**
     * Resolve the (provider, model) for a band, walking a fallback
     * ladder if the band's primary provider isn't registered.
     *
     * Ladder rules:
     *   1. Try the band's own entry.
     *   2. If that provider isn't registered, walk DOWN one band at a
     *      time (cheaper alternatives) until something registered is
     *      found.
     *   3. If nothing below works either, walk UP from the original band.
     *   4. Final fallback: the first entry in the map whose provider is
     *      registered.
     *
     * @param array<string, bool>|null $availabilityOverride Optional
     *        map of `provider => true`. When null, falls back to
     *        `ProviderRegistry::hasProvider()`. Test seam.
     *
     * @return array{provider: string, model: string}
     */
    public function resolve(DifficultyClass $band, ?array $availabilityOverride = null): array
    {
        $isAvailable = function (string $provider) use ($availabilityOverride): bool {
            if ($availabilityOverride !== null) {
                return $availabilityOverride[$provider] ?? false;
            }
            return ProviderRegistry::hasProvider($provider);
        };

        $primary = $this->map[$band->value];
        if ($isAvailable($primary['provider'])) {
            return $primary;
        }

        $ladder = [DifficultyClass::TRIVIAL, DifficultyClass::EASY, DifficultyClass::MODERATE, DifficultyClass::HARD, DifficultyClass::EXPERT];
        $startIdx = array_search($band, $ladder, true);

        // Walk down (cheaper) first
        for ($i = $startIdx - 1; $i >= 0; $i--) {
            $candidate = $this->map[$ladder[$i]->value];
            if ($isAvailable($candidate['provider'])) {
                return $candidate;
            }
        }
        // Then up (more capable)
        for ($i = $startIdx + 1; $i < count($ladder); $i++) {
            $candidate = $this->map[$ladder[$i]->value];
            if ($isAvailable($candidate['provider'])) {
                return $candidate;
            }
        }

        // Final fallback: any registered entry in the map
        foreach ($this->map as $entry) {
            if ($isAvailable($entry['provider'])) {
                return $entry;
            }
        }

        // Nothing registered — return the band's primary anyway so the
        // caller fails with a meaningful "provider X not configured"
        // error instead of an empty result.
        return $primary;
    }

    /**
     * Build a tier map from the eval ScoreCatalog. For each band, picks
     * the highest-scoring model for the dimension associated with the
     * band's typical workload (reasoning for HARD/EXPERT, coding for
     * MODERATE, etc).
     *
     * Bands without catalog scores fall through to the static defaults.
     */
    public static function fromCatalog(ScoreCatalog $catalog): self
    {
        $bandDims = [
            DifficultyClass::TRIVIAL->value  => 'extraction',
            DifficultyClass::EASY->value     => 'coding',
            DifficultyClass::MODERATE->value => 'coding',
            DifficultyClass::HARD->value     => 'reasoning',
            DifficultyClass::EXPERT->value   => 'reasoning',
        ];

        $defaults = self::defaults();
        $map = $defaults;

        foreach ($bandDims as $bandValue => $dim) {
            $best = $catalog->bestModelFor($dim);
            if ($best === null) {
                continue;
            }
            // ScoreCatalog stores model IDs like "anthropic/claude-opus-4-7"
            // or bare names; split on "/" if present.
            [$provider, $model] = self::splitModelId($best, $defaults[$bandValue]['provider']);
            $map[$bandValue] = ['provider' => $provider, 'model' => $model];
        }

        return new self($map);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitModelId(string $modelId, string $fallbackProvider): array
    {
        if (str_contains($modelId, '/')) {
            [$provider, $model] = explode('/', $modelId, 2);
            return [$provider, $model];
        }
        return [$fallbackProvider, $modelId];
    }

    /**
     * Whole-map view, useful for snapshotting into a SquadResult so
     * the caller knows exactly which model handled each subtask
     * without re-running the resolution logic.
     *
     * @return array<string, array{provider: string, model: string}>
     */
    public function toArray(): array
    {
        return $this->map;
    }
}
