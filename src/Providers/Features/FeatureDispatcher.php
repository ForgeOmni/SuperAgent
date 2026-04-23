<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Features;

use SuperAgent\Contracts\LLMProvider;

/**
 * Central dispatcher that walks every entry in `$options['features']` and
 * invokes the matching `FeatureAdapter` against the resolved provider.
 *
 * Providers call this once at the tail of their `buildRequestBody()` so a
 * single `$options = ['features' => [...]]` payload is enough to activate
 * native capabilities across Anthropic, Qwen, GLM, Kimi, MiniMax (and
 * silently no-op for providers that don't support a given feature and
 * didn't declare the feature as `required`).
 *
 * Design notes:
 *   - The adapter registry is a static class map (feature name → adapter
 *     class). Keeping it class-level rather than instance-level means the
 *     providers don't need to be constructed with a dispatcher dependency.
 *   - When `$options['features']` is empty or absent, `apply()` is a hard
 *     no-op — no array mutation, no function calls — so the Compat
 *     lockdown tests continue to see byte-exact request bodies for
 *     callers that don't opt in.
 */
class FeatureDispatcher
{
    /**
     * feature name → adapter FQCN. Registered at bootstrap (see
     * self::registerDefaults()).
     *
     * @var array<string, class-string<FeatureAdapter>>
     */
    private static array $adapters = [];

    /**
     * Register a single adapter, keyed by its `FEATURE_NAME` constant.
     *
     * @param class-string<FeatureAdapter> $adapterClass
     */
    public static function register(string $adapterClass): void
    {
        if (! is_subclass_of($adapterClass, FeatureAdapter::class)) {
            throw new \InvalidArgumentException(
                "Adapter must extend FeatureAdapter: {$adapterClass}",
            );
        }
        $name = $adapterClass::FEATURE_NAME;
        if ($name === '') {
            throw new \InvalidArgumentException(
                "Adapter {$adapterClass} has empty FEATURE_NAME",
            );
        }
        self::$adapters[$name] = $adapterClass;
    }

    /**
     * Register all built-in adapters. Safe to call multiple times —
     * re-registering the same class is a no-op.
     */
    public static function registerDefaults(): void
    {
        foreach ([
            ThinkingAdapter::class,
            AgentTeamsAdapter::class,
            CodeInterpreterAdapter::class,
            PromptCacheKeyAdapter::class,
            DashScopeCacheControlAdapter::class,
        ] as $class) {
            self::register($class);
        }
    }

    /**
     * Drop every registered adapter. Used by tests to isolate registration
     * state between runs.
     */
    public static function reset(): void
    {
        self::$adapters = [];
    }

    /**
     * @return array<string, class-string<FeatureAdapter>>
     */
    public static function registered(): array
    {
        return self::$adapters;
    }

    /**
     * Apply all declared features from `$options` to the outgoing `$body`.
     *
     * `$options['features']` is a map keyed by feature name; each value is
     * the spec for that feature (empty array is a valid "use defaults"
     * spec). Unknown feature names are silently ignored — the ecosystem
     * can ship new adapters ahead of user code without breaking anything.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $body
     */
    public static function apply(LLMProvider $provider, array $options, array &$body): void
    {
        if (self::$adapters === []) {
            self::registerDefaults();
        }

        $features = $options['features'] ?? null;
        if (! is_array($features) || $features === []) {
            return;
        }

        foreach ($features as $name => $spec) {
            if (! is_string($name) || ! isset(self::$adapters[$name])) {
                continue;
            }
            if (! is_array($spec)) {
                // Accept `features: [name => true]` as "use defaults".
                $spec = $spec === true ? [] : ['enabled' => false];
            }
            $adapterClass = self::$adapters[$name];

            if (self::debugMode()) {
                self::warnOnUnknownKeys($name, $adapterClass, $spec);
            }

            $adapterClass::apply($provider, $spec, $body);
        }
    }

    /**
     * Whether to emit warnings for suspicious feature specs. Gated behind
     * `SUPERAGENT_DEBUG=1` so production paths stay fully silent.
     */
    private static function debugMode(): bool
    {
        $env = getenv('SUPERAGENT_DEBUG');
        if ($env === false || $env === '') {
            return false;
        }
        return in_array(strtolower(trim((string) $env)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Compare the caller's spec keys against the adapter's declared
     * `validSpecKeys()`; warn via `error_log()` on any unknown key.
     * Warning only — the unknown keys are still forwarded to the adapter
     * unchanged, in case the caller knows better than the framework.
     *
     * @param class-string<FeatureAdapter> $adapterClass
     * @param array<string, mixed>         $spec
     */
    private static function warnOnUnknownKeys(string $featureName, string $adapterClass, array $spec): void
    {
        $valid = $adapterClass::validSpecKeys();
        if ($valid === null) {
            return;  // free-form spec — skip validation
        }
        $unknown = array_diff(array_keys($spec), $valid);
        foreach ($unknown as $key) {
            error_log(sprintf(
                "[SuperAgent] features.%s: unknown spec key '%s' (valid: %s)",
                $featureName,
                $key,
                implode(', ', $valid),
            ));
        }
    }
}
