<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * LM Studio — local OpenAI-compatible server, default port 1234.
 *
 * LM Studio is a desktop app that wraps llama.cpp with a GUI model
 * library and exposes a Chat Completions endpoint on
 * `http://localhost:1234/v1` by default. From the SDK's perspective
 * it's just a base-URL swap on top of `ChatCompletionsProvider`; this
 * class exists so callers can write
 *
 *   $agent = new Agent(['provider' => 'lmstudio', 'model' => 'qwen2.5-coder-7b']);
 *
 * rather than
 *
 *   $agent = new Agent([
 *       'provider' => 'openai',
 *       'base_url' => 'http://localhost:1234',
 *       'api_key'  => 'lm-studio',   // LM Studio accepts any non-empty string
 *       'model'    => 'qwen2.5-coder-7b',
 *   ]);
 *
 * No API key is meaningful for a local server, so we auto-fill a
 * harmless placeholder when the caller omits one — matches codex-rs's
 * behaviour of treating OSS providers as "no auth" while still
 * emitting an Authorization header because many OpenAI-compat
 * implementations 400 on its absence.
 *
 * Wire path stays Chat Completions: LM Studio hasn't implemented the
 * Responses API, and there's no upside (no `previous_response_id`
 * continuation when the server holds no state anyway).
 */
class LMStudioProvider extends ChatCompletionsProvider
{
    public const DEFAULT_PORT = 1234;

    protected function providerName(): string
    {
        return 'lmstudio';
    }

    protected function defaultRegion(): string
    {
        return 'local';
    }

    protected function regionToBaseUrl(string $region): string
    {
        // LM Studio only runs locally. We ignore $region; callers
        // who want a different host pass `base_url` directly.
        return 'http://localhost:' . self::DEFAULT_PORT;
    }

    protected function defaultModel(): string
    {
        // LM Studio picks the first loaded model when this is absent
        // on the wire, but the SDK requires a concrete model id to
        // route tool schemas / pricing metadata. qwen2.5-coder is a
        // popular choice; callers normally override via config.
        return 'qwen2.5-coder-7b-instruct';
    }

    protected function resolveBearer(array $config): ?string
    {
        // LM Studio ignores the Authorization header entirely, but
        // Guzzle needs something to send and we'd rather not ship a
        // special "auth-less" branch through every provider. Fall
        // back to a harmless placeholder.
        return $config['api_key'] ?? 'lm-studio';
    }

    protected function missingBearerMessage(array $config): string
    {
        // Can't hit this given resolveBearer's fallback, but kept for
        // symmetry with the other providers.
        return 'LM Studio placeholder key could not be synthesised';
    }
}
