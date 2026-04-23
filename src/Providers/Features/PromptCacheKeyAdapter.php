<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Features;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\Capabilities\SupportsPromptCacheKey;

/**
 * Routes a session id into the provider's prompt-cache keying mechanism.
 *
 * Motivation: Kimi / OpenAI (recent models) auto-cache the shared prefix
 * of requests that share a stable caller-supplied key, cutting input
 * token cost on multi-turn sessions. But the field name and location
 * vary — Kimi uses top-level `prompt_cache_key`, OpenAI accepts
 * `prompt_cache_key` under `extra_body` on select models. Rather than
 * make every caller know the difference, this adapter lets them pass
 * `features: ['prompt_cache_key' => ['session_id' => $sid]]` and does
 * the per-provider translation.
 *
 * Spec shape:
 *   [
 *     'session_id' => 'session-abc',  // required string, non-empty
 *     'required'   => true|false,      // default false (no-op if provider lacks native support)
 *   ]
 *
 * Providers without native prompt_cache_key:
 *   - `required: false` (default) → silent skip (no CoT fallback; caching
 *     is a performance optimization, not a correctness primitive).
 *   - `required: true`           → FeatureNotSupportedException, like
 *     `ThinkingAdapter` in the same mode.
 *
 */
class PromptCacheKeyAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'prompt_cache_key';

    public static function validSpecKeys(): ?array
    {
        return ['session_id', 'required', 'enabled'];
    }

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        if (self::isDisabled($spec)) {
            return;
        }

        $sessionId = (string) ($spec['session_id'] ?? '');
        if ($sessionId === '') {
            // No session id — nothing to key on. Silent no-op so callers
            // that pass `features: ['prompt_cache_key' => []]` in a
            // template don't have to gate it.
            return;
        }

        if ($provider instanceof SupportsPromptCacheKey) {
            $fragment = $provider->promptCacheKeyFragment($sessionId);
            if ($fragment !== []) {
                self::merge($body, $fragment);
            }
            return;
        }

        if (self::isRequired($spec)) {
            self::fail($provider, $body['model'] ?? null);
        }

        // No native support, not required → silent skip. Prompt caching
        // is a pure perf optimization; falling back to anything would be
        // user-surprising rather than helpful.
    }
}
