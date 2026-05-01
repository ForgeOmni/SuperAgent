<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

// ModelCatalog lives in the same namespace, so no additional use statement is required.

/**
 * Resolves model aliases and shorthand names to canonical model identifiers.
 *
 * Instead of hard-coding a static alias map, the resolver maintains a registry
 * of known models grouped by family. When a shorthand like "OPUS" or "sonnet"
 * is passed, it resolves to the latest known model in that family.
 *
 * New model versions can be registered at runtime, and the resolver will
 * automatically prefer the newest one.
 */
class ModelResolver
{
    /**
     * Known models grouped by family.
     * Each entry: ['id' => full model ID, 'date' => YYYYMMDD or 0, 'family' => family key]
     *
     * @var array<string, array<int, array{id: string, date: int}>>
     */
    protected static array $families = [];

    /**
     * Alias → family mapping. Multiple aliases can point to the same family.
     * Keys are lowercase.
     *
     * @var array<string, string>
     */
    protected static array $aliasToFamily = [];

    /**
     * Direct alias overrides (custom aliases from config).
     * These bypass family resolution entirely.
     *
     * @var array<string, string>
     */
    protected static array $customAliases = [];

    protected static bool $initialized = false;

    /**
     * Set of model ids we have already warned about during this process.
     * Each entry is warned exactly once — repeated `resolve()` calls don't
     * spam the log, but a second model with its own deprecation gets its
     * own warning.
     *
     * @var array<string, true>
     */
    protected static array $warnedDeprecations = [];

    /**
     * Resolve a model name to its canonical identifier.
     *
     * Resolution order:
     *  1. Custom aliases (from config, case-insensitive) — exact override
     *  2. Family lookup — find the latest model in the matched family
     *  3. Return original string unchanged (assumed to be a full model ID)
     */
    public static function resolve(string $model): string
    {
        static::ensureInitialized();

        $resolved = static::resolveInner($model);
        static::warnIfDeprecated($resolved);
        return $resolved;
    }

    /**
     * Pure resolution — no side effects. Split out so the deprecation warner
     * can run on the resolved id without recursing.
     */
    protected static function resolveInner(string $model): string
    {
        $key = strtolower(trim($model));

        if ($key === '') {
            return $model;
        }

        // 1. Custom aliases take absolute precedence
        if (isset(static::$customAliases[$key])) {
            return static::$customAliases[$key];
        }

        // 2. Family alias lookup — resolve to latest in family
        if (isset(static::$aliasToFamily[$key])) {
            $family = static::$aliasToFamily[$key];

            return static::latestInFamily($family) ?? $model;
        }

        // 3. Fuzzy match: check if input is a substring of any family key
        $matched = static::fuzzyMatchFamily($key);
        if ($matched !== null) {
            return static::latestInFamily($matched) ?? $model;
        }

        // 4. Dynamic catalog — picks up families shipped in resources/models.json
        //    and any user override fetched via `superagent models update`.
        $fromCatalog = ModelCatalog::resolveAlias($key);
        if ($fromCatalog !== null) {
            return $fromCatalog;
        }

        // No match — pass through as-is
        return $model;
    }

    /**
     * Emit a one-shot deprecation warning when the resolved model is flagged
     * in the catalog. Goes to error_log so it shows up in CLI stderr and
     * Laravel's php error log without needing a logger dependency. Each
     * `(model, process)` pair warns once.
     *
     * Skipped entirely when SUPERAGENT_SUPPRESS_DEPRECATION=1 is set —
     * useful in CI / scripted contexts that pin to a known-deprecated id
     * deliberately and don't want the noise.
     */
    protected static function warnIfDeprecated(string $resolvedId): void
    {
        if (isset(static::$warnedDeprecations[$resolvedId])) {
            return;
        }
        $suppress = getenv('SUPERAGENT_SUPPRESS_DEPRECATION');
        if (is_string($suppress) && in_array(strtolower(trim($suppress)), ['1', 'true', 'yes', 'on'], true)) {
            static::$warnedDeprecations[$resolvedId] = true;
            return;
        }

        $info = ModelCatalog::deprecation($resolvedId);
        if ($info === null) {
            return;
        }

        static::$warnedDeprecations[$resolvedId] = true;

        $replacement = $info['replaced_by'] ?? null;
        $hint = $replacement !== null
            ? " — switch to '{$replacement}'"
            : '';
        $when = $info['days_left'] >= 0
            ? "retires {$info['deprecated_until']} ({$info['days_left']} days left)"
            : "retired on {$info['deprecated_until']} ({$info['days_left']} days ago)";

        error_log("[SuperAgent] model '{$resolvedId}' is deprecated: {$when}{$hint}. "
            . 'Set SUPERAGENT_SUPPRESS_DEPRECATION=1 to silence.');
    }

    /**
     * Register a known model into the family registry.
     *
     * @param string      $modelId  Full model ID (e.g., "claude-opus-4-20250514")
     * @param string      $family   Family key (e.g., "opus")
     * @param string[]    $aliases  Additional aliases for this family
     * @param int         $date     Version date as YYYYMMDD (0 if undated)
     */
    public static function register(string $modelId, string $family, array $aliases = [], int $date = 0): void
    {
        $family = strtolower($family);

        if (! isset(static::$families[$family])) {
            static::$families[$family] = [];
        }

        static::$families[$family][] = ['id' => $modelId, 'date' => $date];

        // Sort family by date descending so latest is always first
        usort(static::$families[$family], fn ($a, $b) => $b['date'] <=> $a['date']);

        // Register family key itself as an alias
        static::$aliasToFamily[$family] = $family;

        // Register additional aliases
        foreach ($aliases as $alias) {
            static::$aliasToFamily[strtolower($alias)] = $family;
        }
    }

    /**
     * Register custom aliases that bypass family resolution.
     *
     * @param array<string, string> $aliases  shorthand → canonical model ID
     */
    public static function registerAliases(array $aliases): void
    {
        foreach ($aliases as $alias => $canonicalModel) {
            static::$customAliases[strtolower($alias)] = $canonicalModel;
        }
    }

    /**
     * Get the latest (newest) model ID in a family.
     */
    public static function latestInFamily(string $family): ?string
    {
        static::ensureInitialized();
        $family = strtolower($family);

        if (empty(static::$families[$family])) {
            return null;
        }

        return static::$families[$family][0]['id'];
    }

    /**
     * Get all models in a family, sorted newest first.
     *
     * @return string[]
     */
    public static function familyModels(string $family): array
    {
        static::ensureInitialized();
        $family = strtolower($family);

        return array_map(
            fn ($entry) => $entry['id'],
            static::$families[$family] ?? [],
        );
    }

    /**
     * Check if a model string is an alias (not a full model ID).
     */
    public static function isAlias(string $model): bool
    {
        static::ensureInitialized();
        $key = strtolower(trim($model));

        return isset(static::$customAliases[$key])
            || isset(static::$aliasToFamily[$key])
            || static::fuzzyMatchFamily($key) !== null;
    }

    /**
     * Get all known families and their latest models.
     *
     * @return array<string, string>  family → latest model ID
     */
    public static function allFamilies(): array
    {
        static::ensureInitialized();

        $result = [];
        foreach (static::$families as $family => $models) {
            if (! empty($models)) {
                $result[$family] = $models[0]['id'];
            }
        }

        return $result;
    }

    /**
     * Reset all state (useful for testing).
     */
    public static function reset(): void
    {
        static::$families = [];
        static::$aliasToFamily = [];
        static::$customAliases = [];
        static::$initialized = false;
        static::$warnedDeprecations = [];
    }

    /**
     * Fuzzy match: check if the input matches a family key.
     * Tries exact substring match on family keys, then on alias keys.
     */
    protected static function fuzzyMatchFamily(string $input): ?string
    {
        // Try if input is contained in a family key, or a family key is contained in input
        foreach (static::$families as $family => $models) {
            if (str_contains($family, $input) || str_contains($input, $family)) {
                return $family;
            }
        }

        // Try alias keys
        foreach (static::$aliasToFamily as $alias => $family) {
            if (str_contains($alias, $input) || str_contains($input, $alias)) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Initialize the built-in model registry.
     */
    protected static function ensureInitialized(): void
    {
        if (static::$initialized) {
            return;
        }

        static::$initialized = true;

        // ── Anthropic Claude ──────────────────────────────────────

        // Opus family
        static::register('claude-opus-4-20250514', 'opus', [
            'claude-opus', 'claude-opus-4',
        ], 20250514);
        static::register('claude-3-opus-20240229', 'opus', [], 20240229);

        // Sonnet family
        static::register('claude-sonnet-4-20250514', 'sonnet', [
            'claude-sonnet', 'claude-sonnet-4',
        ], 20250514);
        static::register('claude-3-5-sonnet-20241022', 'sonnet', [
            'claude-3.5-sonnet', 'claude-3-5-sonnet',
        ], 20241022);
        static::register('claude-3-sonnet-20240229', 'sonnet', [], 20240229);

        // Haiku family
        static::register('claude-haiku-4-5-20251001', 'haiku', [
            'claude-haiku', 'claude-haiku-4', 'claude-haiku-4.5',
        ], 20251001);
        static::register('claude-3-haiku-20240307', 'haiku', [
            'claude-3-haiku',
        ], 20240307);

        // ── OpenAI ───────────────────────────────────────────────

        // GPT-4o family
        static::register('gpt-4o', 'gpt-4o', [
            'gpt4', 'gpt4o',
        ], 20240513);
        static::register('gpt-4o-mini', 'gpt-4o-mini', [
            'gpt4o-mini', 'gpt4-mini',
        ], 20240718);

        // GPT-3.5 family
        static::register('gpt-3.5-turbo', 'gpt-3.5', [
            'gpt35', 'gpt3.5', 'gpt-3.5-turbo',
        ], 20230613);

        // ── Google Gemini ─────────────────────────────────────────

        static::register('gemini-2.0-flash', 'gemini-flash', [
            'gemini', 'gemini-flash', 'gemini-2', 'gemini-2-flash',
        ], 20250205);
        static::register('gemini-1.5-flash', 'gemini-flash', [], 20240924);

        static::register('gemini-2.5-pro', 'gemini-pro', [
            'gemini-pro', 'gemini-2.5', 'gemini-2-5-pro',
        ], 20250325);
        static::register('gemini-1.5-pro', 'gemini-pro', [
            'gemini-1-5-pro',
        ], 20240924);
    }
}
