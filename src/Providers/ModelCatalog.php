<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Single source of truth for model catalog + pricing across all providers.
 *
 * Resolution order (later sources override earlier ones):
 *   1. Bundled baseline — `resources/models.json` shipped with the package.
 *   2. User override    — `~/.superagent/models.json` (writable; managed by `superagent models update`).
 *   3. Remote URL       — fetched on demand by `refreshFromRemote()` or the CLI.
 *   4. Runtime overrides — `register()` / `loadFromFile()` (highest precedence).
 *
 * Consumers:
 *   - `CostCalculator::resolve()` — looks up per-model pricing.
 *   - `ModelResolver` — derives family → latest mapping from `family` / `date` / `aliases`.
 *   - `CommandRouter /model` — builds the interactive picker from `providers[*].models`.
 *
 * The user's request drove this abstraction: model lists / pricing change quickly,
 * and shipping a JSON file that can be refreshed without a package release keeps
 * the CLI usable in the face of a fast-moving model landscape.
 *
 * Schema versioning:
 *   - v1 (baseline) — each model has `id`, optional pricing/family/date/aliases/description.
 *   - v2 (additive) — adds optional `capabilities` and `regions` on model entries, and
 *     provider-level defaults under `providers[p].capabilities` / `providers[p].regions`
 *     that model entries inherit when they don't set their own. The loader accepts both
 *     versions transparently; `_meta.schema_version` is informational only.
 *     See `design/NATIVE_PROVIDERS_CN.md` §4.7 for the full v2 spec.
 */
class ModelCatalog
{
    /** @var array<string, mixed>|null Flat model-id → entry map (lazy). */
    private static ?array $byId = null;

    /** @var array<string, array<int, array<string, mixed>>>|null provider → models[] */
    private static ?array $byProvider = null;

    /** @var array<string, array<string, mixed>> Runtime register() overrides keyed by id. */
    private static array $overrides = [];

    /** Whether the built-in + user-override sources have been loaded. */
    private static bool $sourcesLoaded = false;

    /**
     * Pricing + catalog entry for a specific model id.
     * Returns null if the model isn't known.
     *
     * @return array{id:string, provider?:string, family?:string, date?:int, input?:float, output?:float, description?:string, aliases?:array<int,string>}|null
     */
    public static function model(string $id): ?array
    {
        self::ensureLoaded();
        return self::$byId[$id] ?? null;
    }

    /**
     * Pricing row `['input' => ..., 'output' => ...]` per million tokens, or null.
     *
     * @return array{input: float, output: float}|null
     */
    public static function pricing(string $id): ?array
    {
        $entry = self::model($id);
        if ($entry === null || ! isset($entry['input'], $entry['output'])) {
            return null;
        }

        return ['input' => (float) $entry['input'], 'output' => (float) $entry['output']];
    }

    /**
     * All models for a given provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function modelsFor(string $provider): array
    {
        self::ensureLoaded();
        return self::$byProvider[$provider] ?? [];
    }

    /**
     * All registered provider keys.
     *
     * @return array<int, string>
     */
    public static function providers(): array
    {
        self::ensureLoaded();
        return array_keys(self::$byProvider ?? []);
    }

    /**
     * Full flat catalog (id → entry).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        self::ensureLoaded();
        return self::$byId ?? [];
    }

    /**
     * Capabilities for a given model id.
     *
     * Resolution order:
     *   1. Explicit `capabilities` on the model entry (schema v2).
     *   2. Provider-level `capabilities` default (schema v2, merged in at ingest time).
     *   3. `ProviderRegistry::getCapabilities(<provider>)` — hard-coded per-provider map
     *      kept for backward compatibility with v1 catalogs that pre-date schema v2.
     *
     * Returns an empty array when nothing is known. The shape is intentionally
     * loose — callers should treat missing keys as "unknown/unsupported".
     *
     * @return array<string, mixed>
     */
    public static function capabilitiesFor(string $id): array
    {
        $entry = self::model($id);
        if ($entry === null) {
            return [];
        }

        if (isset($entry['capabilities']) && is_array($entry['capabilities'])) {
            return $entry['capabilities'];
        }

        $provider = $entry['provider'] ?? null;
        if (! is_string($provider) || $provider === '') {
            return [];
        }

        return ProviderRegistry::getCapabilities($provider);
    }

    /**
     * Regions a given model is available in (schema v2). Empty array means the
     * model makes no claim about regions — callers should treat it as "default /
     * single region" and not filter.
     *
     * @return array<int, string>
     */
    public static function regionsFor(string $id): array
    {
        $entry = self::model($id);
        if ($entry === null) {
            return [];
        }
        $regions = $entry['regions'] ?? [];
        if (! is_array($regions)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $regions), static fn ($r) => $r !== ''));
    }

    /**
     * Resolve an alias or family shorthand to a canonical model id.
     * Finds the newest entry whose `family` or `aliases` matches (case-insensitive).
     */
    public static function resolveAlias(string $alias): ?string
    {
        self::ensureLoaded();
        $key = strtolower(trim($alias));
        if ($key === '') {
            return null;
        }

        $best = null;
        $bestDate = -1;
        foreach (self::$byId ?? [] as $id => $entry) {
            $family = strtolower((string) ($entry['family'] ?? ''));
            $aliases = array_map('strtolower', $entry['aliases'] ?? []);
            if ($family === $key || in_array($key, $aliases, true)) {
                $date = (int) ($entry['date'] ?? 0);
                if ($date >= $bestDate) {
                    $best = $id;
                    $bestDate = $date;
                }
            }
        }

        return $best;
    }

    /**
     * Register or override a single model row at runtime (highest precedence).
     *
     * @param array{input?:float, output?:float, family?:string, date?:int|string, aliases?:array<int,string>, description?:string, provider?:string} $entry
     */
    public static function register(string $id, array $entry): void
    {
        $entry['id'] = $id;
        if (isset($entry['date']) && is_string($entry['date'])) {
            $entry['date'] = (int) $entry['date'];
        }
        self::$overrides[$id] = $entry;
        self::invalidate();
    }

    /**
     * Replace the entire catalog from a JSON file — same schema as `resources/models.json`.
     * Clears bundled/user sources for the remainder of the process; subsequent `register()`
     * calls still apply on top.
     *
     * @throws \RuntimeException when the file is missing or not valid JSON.
     */
    public static function loadFromFile(string $path): void
    {
        if (! is_readable($path)) {
            throw new \RuntimeException("Model catalog file not readable: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read model catalog: {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['providers']) || ! is_array($data['providers'])) {
            throw new \RuntimeException("Invalid model catalog format: {$path}");
        }

        self::invalidate();
        self::$sourcesLoaded = true;
        self::ingest($data);
    }

    /**
     * Fetch remote JSON (schema matches `resources/models.json`), validate, write to
     * `~/.superagent/models.json`, and reload. Returns the number of models loaded.
     *
     * @throws \RuntimeException on network / validation / filesystem failure.
     */
    public static function refreshFromRemote(?string $url = null, int $timeoutSeconds = 20): int
    {
        $url ??= self::remoteUrl();
        if ($url === null || $url === '') {
            throw new \RuntimeException(
                'No remote URL configured. Set SUPERAGENT_MODELS_URL or pass a url explicitly.'
            );
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => $timeoutSeconds, 'user_agent' => 'SuperAgent-ModelCatalog/1'],
            'https' => ['timeout' => $timeoutSeconds, 'user_agent' => 'SuperAgent-ModelCatalog/1'],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException("Failed to fetch models from: {$url}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['providers']) || ! is_array($data['providers'])) {
            throw new \RuntimeException("Remote response is not a valid model catalog: {$url}");
        }

        // Persist to the user override path atomically (temp + rename).
        $path = self::userOverridePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $raw) === false) {
            throw new \RuntimeException("Failed to write temp catalog: {$tmp}");
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to promote temp catalog: {$path}");
        }
        @chmod($path, 0644);

        self::invalidate();
        self::ensureLoaded();

        $count = 0;
        foreach ($data['providers'] as $entries) {
            $count += count($entries['models'] ?? []);
        }
        return $count;
    }

    /**
     * True when the user override file is older than `$maxAgeSeconds` (default 7 days)
     * — or missing entirely. Used by the CLI to hint at running `models update`.
     */
    public static function isStale(int $maxAgeSeconds = 604800): bool
    {
        $mtime = self::userOverrideMtime();
        if ($mtime === null) {
            return true;
        }
        return (time() - $mtime) > $maxAgeSeconds;
    }

    /**
     * Opt-in auto-refresh. Runs a single network call when:
     *   - SUPERAGENT_MODELS_AUTO_UPDATE env var is truthy ("1" / "true"), AND
     *   - SUPERAGENT_MODELS_URL is set, AND
     *   - the local catalog is stale per `isStale()`.
     *
     * Failures are swallowed so a dead remote never blocks CLI startup. Callers
     * should invoke this at most once per process.
     */
    public static function maybeAutoUpdate(int $timeoutSeconds = 5): bool
    {
        $flag = strtolower((string) getenv('SUPERAGENT_MODELS_AUTO_UPDATE'));
        if (! in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }
        if (self::remoteUrl() === null) {
            return false;
        }
        if (! self::isStale()) {
            return false;
        }
        try {
            self::refreshFromRemote(null, $timeoutSeconds);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Delete the user override file so subsequent loads use only the bundled catalog.
     */
    public static function resetUserOverride(): bool
    {
        $path = self::userOverridePath();
        if (! file_exists($path)) {
            return false;
        }
        self::invalidate();
        return @unlink($path);
    }

    /**
     * Mtime of the user override file (for `models status`), or null if absent.
     */
    public static function userOverrideMtime(): ?int
    {
        $path = self::userOverridePath();
        if (! file_exists($path)) {
            return null;
        }
        $t = @filemtime($path);
        return $t === false ? null : $t;
    }

    public static function userOverridePath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.superagent/models.json';
    }

    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/models.json';
    }

    public static function remoteUrl(): ?string
    {
        $url = getenv('SUPERAGENT_MODELS_URL');
        return $url === false || $url === '' ? null : $url;
    }

    /**
     * Drop cached state so the next consumer call re-reads sources.
     * Primarily for tests, but also triggered after any write.
     */
    public static function invalidate(): void
    {
        self::$byId = null;
        self::$byProvider = null;
        self::$sourcesLoaded = false;
    }

    /**
     * Drop runtime `register()` overrides. Primarily for test isolation — production
     * callers rarely need this because overrides are meant to live for the process.
     */
    public static function clearOverrides(): void
    {
        self::$overrides = [];
        self::invalidate();
    }

    // ── Internal loading ──────────────────────────────────────────

    private static function ensureLoaded(): void
    {
        if (self::$byId !== null) {
            return;
        }
        self::$byId = [];
        self::$byProvider = [];

        if (! self::$sourcesLoaded) {
            $bundled = self::bundledPath();
            if (is_readable($bundled)) {
                $raw = file_get_contents($bundled);
                $data = $raw === false ? null : json_decode($raw, true);
                if (is_array($data)) {
                    self::ingest($data);
                }
            }

            $override = self::userOverridePath();
            if (is_readable($override)) {
                $raw = file_get_contents($override);
                $data = $raw === false ? null : json_decode($raw, true);
                if (is_array($data)) {
                    self::ingest($data);
                }
            }

            self::$sourcesLoaded = true;
        }

        // Apply runtime overrides last so they take precedence over file sources.
        foreach (self::$overrides as $id => $entry) {
            self::insert($id, $entry);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function ingest(array $data): void
    {
        $providers = $data['providers'] ?? [];
        if (! is_array($providers)) {
            return;
        }
        foreach ($providers as $providerKey => $providerBlock) {
            $models = $providerBlock['models'] ?? [];
            if (! is_array($models)) {
                continue;
            }

            // Schema v2: provider-level defaults for `capabilities` and `regions`
            // apply to every model in this block unless the model overrides them.
            $providerDefaults = [];
            if (isset($providerBlock['capabilities']) && is_array($providerBlock['capabilities'])) {
                $providerDefaults['capabilities'] = $providerBlock['capabilities'];
            }
            if (isset($providerBlock['regions']) && is_array($providerBlock['regions'])) {
                $providerDefaults['regions'] = $providerBlock['regions'];
            }

            foreach ($models as $model) {
                if (! is_array($model) || ! isset($model['id'])) {
                    continue;
                }
                $model['provider'] = (string) $providerKey;
                if (isset($model['date']) && is_string($model['date'])) {
                    $model['date'] = (int) $model['date'];
                }

                // Model-level capabilities/regions win; otherwise inherit from provider block.
                if (isset($providerDefaults['capabilities']) && ! isset($model['capabilities'])) {
                    $model['capabilities'] = $providerDefaults['capabilities'];
                }
                if (isset($providerDefaults['regions']) && ! isset($model['regions'])) {
                    $model['regions'] = $providerDefaults['regions'];
                }

                self::insert((string) $model['id'], $model);
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function insert(string $id, array $entry): void
    {
        $entry['id'] = $id;
        self::$byId[$id] = array_merge(self::$byId[$id] ?? [], $entry);

        $provider = (string) ($entry['provider'] ?? 'unknown');
        self::$byProvider[$provider] ??= [];

        // Replace existing entry with the same id (from an earlier source).
        $replaced = false;
        foreach (self::$byProvider[$provider] as $i => $existing) {
            if (($existing['id'] ?? null) === $id) {
                self::$byProvider[$provider][$i] = self::$byId[$id];
                $replaced = true;
                break;
            }
        }
        if (! $replaced) {
            self::$byProvider[$provider][] = self::$byId[$id];
        }
    }
}
