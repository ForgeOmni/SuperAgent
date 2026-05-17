<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Three-tier squad team catalog — same pattern `ModelCatalog` already
 * uses for model entries, applied to YAML team definitions:
 *
 *   1. **Bundled**       — `resources/squad-teams/*.yaml` shipped with
 *                          the SDK. Immutable; the single authoritative
 *                          source for the SDK-provided team library.
 *   2. **Directories**   — Host-registered extra directories. Hosts
 *                          call `addDirectory($path)` at boot to layer
 *                          additional team files on top. Later
 *                          directories override earlier ones; the host
 *                          can also override a bundled team by name.
 *   3. **Runtime**       — `register($name, $plan)` injects a
 *                          programmatic team that wasn't loaded from
 *                          disk. Useful for tests + dynamically
 *                          composed teams.
 *
 * Resolution order: runtime > directories (last-added wins) > bundled.
 *
 * Why this lives in SuperAgent and not in each host:
 *
 *   - The 18+ bundled teams are the SINGLE source of truth — every
 *     host that ships SuperAgent gets the same library without
 *     copy-pasting YAMLs.
 *   - Hosts can still extend (their own team) or override (replace a
 *     bundled team) by registering their own directory at boot.
 *   - SuperAgent itself never depends on a host — the registry sits
 *     entirely in this package.
 *
 * Listing semantics: `list()` returns every team name that has at
 * least one tier hit; subsequent `load()` resolves through the
 * priority chain. This means an override is invisible in the listing
 * (the team appears once, under its bundled name) — exactly the
 * behaviour `ModelCatalog` gives for model aliases.
 *
 * Lazy parsing: YAML files in registered directories are parsed only
 * on first `load()` for that name — so `list()` is cheap and
 * directories with broken YAML don't break the whole registry until
 * the bad file is actually requested.
 */
final class TeamRegistry
{
    /** @var list<string> Bundled directory, plus any host-added ones. */
    private array $directories = [];

    /** @var array<string, SquadPlan> Runtime-registered plans. */
    private array $runtime = [];

    /** @var array<string, string>|null Lazily-discovered name → path index. */
    private ?array $fileIndex = null;

    private readonly YamlSquadLoader $loader;

    public function __construct(?string $bundledDir = null)
    {
        $this->loader = new YamlSquadLoader();
        $bundledDir ??= self::defaultBundledDir();
        if (is_dir($bundledDir)) {
            $this->directories[] = $bundledDir;
        }
    }

    /**
     * Path to the SDK's bundled team library. Hosts that need to
     * surface it (e.g. CLI auto-completion) can call this without
     * instantiating the registry.
     */
    public static function defaultBundledDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources'
             . DIRECTORY_SEPARATOR . 'squad-teams';
    }

    /**
     * Add another directory of YAML team files. Files in later-added
     * directories override files in earlier ones (same name = later
     * wins). Idempotent — adding the same path twice is a no-op.
     */
    public function addDirectory(string $path): self
    {
        if (!is_dir($path)) return $this;
        if (in_array($path, $this->directories, true)) return $this;
        $this->directories[] = $path;
        $this->fileIndex = null; // invalidate
        return $this;
    }

    /**
     * Register a `SquadPlan` programmatically. Wins over any
     * directory-sourced team with the same name (matches the
     * runtime tier in `ModelCatalog`).
     */
    public function register(string $name, SquadPlan $plan): self
    {
        $this->runtime[$name] = $plan;
        return $this;
    }

    /** Drop a runtime registration. */
    public function unregister(string $name): self
    {
        unset($this->runtime[$name]);
        return $this;
    }

    /**
     * List every team name known to the registry. Each name appears
     * once even when multiple tiers carry it. Order: alphabetical
     * (stable across calls).
     *
     * @return list<string>
     */
    public function list(): array
    {
        $names = array_keys(array_merge($this->indexFiles(), $this->runtime));
        sort($names);
        return $names;
    }

    /**
     * Look up a team by name. Returns null when no tier has it. The
     * YAML for directory-sourced teams is parsed lazily — a broken
     * YAML file only throws when its team is actually requested.
     */
    public function load(string $name): ?SquadPlan
    {
        if (isset($this->runtime[$name])) {
            return $this->runtime[$name];
        }
        $index = $this->indexFiles();
        if (!isset($index[$name])) return null;
        return $this->loader->loadFile($index[$name]);
    }

    /**
     * Like `load()` but throws when missing. Use when the caller
     * has no useful fallback for a missing team.
     */
    public function require(string $name): SquadPlan
    {
        $plan = $this->load($name);
        if ($plan === null) {
            throw new \InvalidArgumentException("No squad team registered under name '{$name}'.");
        }
        return $plan;
    }

    /**
     * Where (which tier) a given team comes from. Useful for the
     * `superagent squad show <name>` UX to disclose
     * "bundled / overridden by /path/to/dir / runtime".
     *
     * @return array{tier:'runtime'|'directory'|'bundled', source:string}|null
     */
    public function origin(string $name): ?array
    {
        if (isset($this->runtime[$name])) {
            return ['tier' => 'runtime', 'source' => '<runtime>'];
        }
        $index = $this->indexFiles();
        if (!isset($index[$name])) return null;
        $path = $index[$name];
        $isBundled = $path !== '' && str_starts_with($path, self::defaultBundledDir() . DIRECTORY_SEPARATOR);
        return [
            'tier'   => $isBundled ? 'bundled' : 'directory',
            'source' => $path,
        ];
    }

    /**
     * Build the name → path index. Later directories override earlier
     * ones — that's the layering policy hosts rely on for overrides.
     *
     * Files are matched by basename without extension. We accept
     * `.yaml` and `.yml`. Non-yaml files are ignored so callers can
     * drop README.md / TEMPLATE.md alongside their teams without
     * confusing the registry.
     *
     * @return array<string, string>
     */
    private function indexFiles(): array
    {
        if ($this->fileIndex !== null) return $this->fileIndex;

        $index = [];
        foreach ($this->directories as $dir) {
            $entries = @scandir($dir);
            if ($entries === false) continue;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if ($ext !== 'yaml' && $ext !== 'yml') continue;
                $name = pathinfo($entry, PATHINFO_FILENAME);
                $index[$name] = $dir . DIRECTORY_SEPARATOR . $entry;
            }
        }
        return $this->fileIndex = $index;
    }
}
