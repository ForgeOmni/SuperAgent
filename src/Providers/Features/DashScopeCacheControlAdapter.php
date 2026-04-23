<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Features;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\QwenProvider;

/**
 * DashScope prompt caching — block-level markers.
 *
 * Alibaba's OpenAI-compatible endpoint supports Anthropic-style
 * `cache_control: {type: 'ephemeral'}` markers. qwen-code
 * (`packages/core/src/core/openaiContentGenerator/provider/dashscope.ts:40-54`)
 * pins markers on three specific positions per request, plus emits
 * the toggle header `X-DashScope-CacheControl: enable`:
 *
 *   1. The system message.
 *   2. The last tool definition (tools block is cached as a unit).
 *   3. The latest message in history — BUT only when streaming, so
 *      non-streaming single-shot requests don't pollute the cache
 *      key space with user content that changes every call.
 *
 * Spec shape:
 *   [
 *     'enabled'  => true|false,  // default true
 *     'required' => true|false,  // default false (silent skip on non-Qwen)
 *   ]
 *
 * The header side of the flag (`X-DashScope-CacheControl: enable`) is
 * emitted unconditionally by `QwenProvider::extraHeaders()` — it's
 * harmless when the body has no markers, and turning it on selectively
 * per request would require touching the Guzzle client per call. This
 * adapter only writes the body-side markers.
 *
 * Non-Qwen providers:
 *   - `required: false` (default) → silent skip (caching is a perf
 *     optimization; falling back to CoT-style prompt rewrite would be
 *     user-surprising).
 *   - `required: true` → FeatureNotSupportedException.
 */
class DashScopeCacheControlAdapter extends FeatureAdapter
{
    public const FEATURE_NAME = 'dashscope_cache_control';

    public static function validSpecKeys(): ?array
    {
        return ['enabled', 'required'];
    }

    public static function apply(LLMProvider $provider, array $spec, array &$body): void
    {
        if (self::isDisabled($spec)) {
            return;
        }

        if (! $provider instanceof QwenProvider) {
            if (self::isRequired($spec)) {
                self::fail($provider, $body['model'] ?? null);
            }
            return;
        }

        self::markSystemMessage($body);
        self::markLastTool($body);
        self::markLastMessageIfStreaming($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function markSystemMessage(array &$body): void
    {
        if (empty($body['messages']) || ! is_array($body['messages'])) {
            return;
        }
        $first = $body['messages'][0] ?? null;
        if (! is_array($first) || ($first['role'] ?? null) !== 'system') {
            return;
        }
        $body['messages'][0] = self::withCacheControl($first);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function markLastTool(array &$body): void
    {
        if (empty($body['tools']) || ! is_array($body['tools'])) {
            return;
        }
        $lastKey = array_key_last($body['tools']);
        if ($lastKey === null) {
            return;
        }
        $tool = $body['tools'][$lastKey];
        if (! is_array($tool)) {
            return;
        }
        $body['tools'][$lastKey] = self::withCacheControl($tool);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function markLastMessageIfStreaming(array &$body): void
    {
        if (empty($body['stream'])) {
            return;
        }
        if (empty($body['messages']) || ! is_array($body['messages'])) {
            return;
        }
        $lastKey = array_key_last($body['messages']);
        if ($lastKey === null || $lastKey === 0) {
            return;   // only the system message exists; already marked.
        }
        $last = $body['messages'][$lastKey];
        if (! is_array($last) || ($last['role'] ?? null) === 'system') {
            return;
        }
        $body['messages'][$lastKey] = self::withCacheControl($last);
    }

    /**
     * Attach the marker, preserving any caller-supplied cache_control.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function withCacheControl(array $entry): array
    {
        if (! isset($entry['cache_control'])) {
            $entry['cache_control'] = ['type' => 'ephemeral'];
        }
        return $entry;
    }
}
