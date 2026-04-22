<?php

declare(strict_types=1);

namespace SuperAgent\Config;

/**
 * Lightweight feature flag registry — lets users disable framework
 * capabilities without writing custom provider / adapter config.
 *
 * Flags come from, in precedence order:
 *   1. Runtime overrides set via `FeatureFlags::override($key, $value)`.
 *   2. `SUPERAGENT_DISABLE` env (comma-separated list of flag keys to
 *      force-disable).
 *   3. `~/.superagent/features.json` when present.
 *   4. Compile-time defaults (below).
 *
 * Naming: dotted paths, short. Examples:
 *   - `thinking`               → ThinkingAdapter as a whole
 *   - `cost_limit`             → CostLimiter enforcement
 *   - `network_policy`         → NetworkPolicy offline check
 *   - `skills.user_dir`        → SkillManager auto-loading ~/.superagent/skills
 *   - `mcp.user_config`        → MCPManager reading ~/.superagent/mcp.json
 *
 * Call `FeatureFlags::enabled($key)` at the guard point. Default for any
 * unknown key is `true` so the framework can ship new capabilities
 * without requiring users to opt in.
 */
final class FeatureFlags
{
    /** @var array<string, bool> */
    private static array $overrides = [];

    /** @var array<string, bool>|null */
    private static ?array $loaded = null;

    /**
     * Flags that are off by default. Everything not listed here defaults
     * to `true` (fail-open: new features ship enabled).
     *
     * @var array<string, bool>
     */
    private const OFF_BY_DEFAULT = [
        // No default-off flags at v0.8.8 — reserved for experimental toggles.
    ];

    public static function enabled(string $key): bool
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        self::loadIfNeeded();
        if (array_key_exists($key, self::$loaded)) {
            return self::$loaded[$key];
        }

        return ! array_key_exists($key, self::OFF_BY_DEFAULT) || self::OFF_BY_DEFAULT[$key];
    }

    /**
     * Programmatic override. Set `null` to clear and fall back to
     * env / file / default. Primarily a test hook; production callers
     * prefer setting `SUPERAGENT_DISABLE` so subprocesses inherit the flag.
     */
    public static function override(string $key, ?bool $value): void
    {
        if ($value === null) {
            unset(self::$overrides[$key]);
            return;
        }
        self::$overrides[$key] = $value;
    }

    /**
     * Drop the cached file read + every runtime override. Tests use this
     * to isolate flag state between cases.
     */
    public static function reset(): void
    {
        self::$overrides = [];
        self::$loaded = null;
    }

    /**
     * @return array<string, bool> Current effective flag map (for introspection).
     */
    public static function snapshot(): array
    {
        self::loadIfNeeded();
        return array_merge(self::OFF_BY_DEFAULT, self::$loaded, self::$overrides);
    }

    public static function configPath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim($home, '/\\') . '/.superagent/features.json';
    }

    private static function loadIfNeeded(): void
    {
        if (self::$loaded !== null) {
            return;
        }
        self::$loaded = [];

        // Env-based disable list takes precedence over file-based config —
        // makes it trivial to disable a flag just for one invocation.
        $disabled = (string) (getenv('SUPERAGENT_DISABLE') ?: '');
        if ($disabled !== '') {
            foreach (array_filter(array_map('trim', explode(',', $disabled))) as $key) {
                self::$loaded[$key] = false;
            }
        }

        $path = self::configPath();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $key => $value) {
                        if (is_string($key) && ! array_key_exists($key, self::$loaded)) {
                            // Env `disabled` list wins over file; don't let the
                            // file re-enable something explicitly disabled for
                            // this process.
                            self::$loaded[$key] = (bool) $value;
                        }
                    }
                }
            }
        }
    }
}
