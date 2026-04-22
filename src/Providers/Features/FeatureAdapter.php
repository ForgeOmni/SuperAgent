<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Features;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Exceptions\FeatureNotSupportedException;

/**
 * Base class for Feature adapters — the translation layer between a generic
 * `features` spec on an outgoing request and whatever provider-specific
 * request fields (or fallback prompt injection) are needed to honour it.
 *
 * Adapters are intentionally static. They read the feature spec plus the
 * target `LLMProvider`, inspect what Capability interfaces the provider
 * declares via `instanceof`, and either:
 *
 *   1. emit a provider-specific body fragment (via
 *      `SupportsThinking::thinkingRequestFragment()` etc.) and merge it
 *      into the outbound `$body`, OR
 *   2. fall back to a generic equivalent (e.g. inject a "Think step by
 *      step" system prompt when the provider lacks native thinking), OR
 *   3. raise `FeatureNotSupportedException` when the spec set
 *      `required: true` and neither native nor fallback is available.
 *
 * Concrete adapters (ThinkingAdapter, WebSearchAdapter, …) are added in
 * Phase 3. This base class intentionally contains only the shared
 * plumbing: spec parsing, required-flag handling, and the `$body` merge.
 */
abstract class FeatureAdapter
{
    /**
     * Feature name as it appears under `options.features.<name>`.
     * Concrete subclasses MUST override.
     */
    public const FEATURE_NAME = '';

    /**
     * Apply this feature to the outgoing request body.
     *
     * Concrete subclasses implement the provider routing table. The default
     * contract:
     *   - if `$spec['enabled']` is explicitly false, adapter is a no-op
     *   - if provider declares the capability interface → emit native fragment
     *   - else if adapter has a fallback → emit fallback fragment
     *   - else if `$spec['required']` is true → throw FeatureNotSupportedException
     *   - else silently no-op
     *
     * @param array<string, mixed> $spec    The per-feature spec from
     *                                      `$options['features'][<name>]`.
     * @param array<string, mixed> $body    Outbound request body, mutated
     *                                      in place.
     */
    abstract public static function apply(LLMProvider $provider, array $spec, array &$body): void;

    /**
     * Helper: raise the "required feature not supported" exception with the
     * adapter's feature name baked in.
     */
    protected static function fail(LLMProvider $provider, ?string $model = null): never
    {
        throw new FeatureNotSupportedException(
            feature: static::FEATURE_NAME,
            provider: $provider->name(),
            model: $model,
        );
    }

    /**
     * Helper: whether the spec requires this feature to be satisfied natively
     * or via fallback — i.e. the caller wants a hard error rather than silent
     * drop if neither works out. Defaults to false (graceful degrade).
     *
     * @param array<string, mixed> $spec
     */
    protected static function isRequired(array $spec): bool
    {
        return ! empty($spec['required']);
    }

    /**
     * Helper: whether the spec explicitly disables this feature (rare — used
     * by callers that want to override a global default).
     *
     * @param array<string, mixed> $spec
     */
    protected static function isDisabled(array $spec): bool
    {
        return array_key_exists('enabled', $spec) && $spec['enabled'] === false;
    }

    /**
     * Helper: deep-merge a provider-specific body fragment into the outgoing
     * request body. Existing keys are preserved at the leaf level unless the
     * fragment explicitly overwrites them — this keeps downstream fields
     * (messages, model, temperature) safe from adapter collisions.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fragment
     */
    protected static function merge(array &$body, array $fragment): void
    {
        foreach ($fragment as $k => $v) {
            if (is_array($v) && isset($body[$k]) && is_array($body[$k])) {
                // Recurse only for associative subarrays; indexed lists
                // (messages, tools, etc.) should be replaced wholesale by
                // whatever the adapter provided.
                if (self::isAssoc($v) && self::isAssoc($body[$k])) {
                    $sub = $body[$k];
                    self::merge($sub, $v);
                    $body[$k] = $sub;
                    continue;
                }
            }
            $body[$k] = $v;
        }
    }

    /**
     * @param array<int|string, mixed> $a
     */
    private static function isAssoc(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        return array_keys($a) !== range(0, count($a) - 1);
    }
}
