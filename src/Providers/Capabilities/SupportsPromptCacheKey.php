<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider supports session-level prompt caching via a stable
 * caller-supplied key.
 *
 * This is deliberately a different interface from `SupportsContextCaching`,
 * which models Anthropic's block-level `cache_control` breakpoints.
 * Kimi (Moonshot) doesn't mark individual message blocks — instead the
 * client passes a stable `prompt_cache_key` (typically the session id)
 * and the server transparently caches the shared prefix of requests
 * sharing that key. OpenAI has a similar `user` / `prompt_cache_key`
 * field under `extra_body` on recent models.
 *
 * Contract:
 *   - `promptCacheKeyFragment($sessionId)` returns a provider-specific
 *     request-body fragment (e.g. `['prompt_cache_key' => $sessionId]`
 *     for Kimi). The adapter deep-merges it into the request body.
 *   - Providers SHOULD NOT truncate / hash the session id themselves —
 *     the caller is free to pass whatever stable string they already
 *     use for session identification.
 *   - Returning `[]` is a valid "silently skip" signal for providers
 *     that accept the interface for compatibility but have no native
 *     key-based caching in this context.
 */
interface SupportsPromptCacheKey
{
    /**
     * Build the request-body fragment that wires `$sessionId` into the
     * provider's prompt-cache keying mechanism.
     *
     * @return array<string, mixed>
     */
    public function promptCacheKeyFragment(string $sessionId): array;
}
