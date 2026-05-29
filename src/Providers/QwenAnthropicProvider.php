<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Qwen 3.7 Max via Anthropic-protocol-compatible endpoint.
 *
 * Qwen 3.7 Max (released 2026-05-21) ships native Anthropic API protocol
 * support — the `/v1/messages` wire is byte-compatible with Anthropic's
 * canonical shape. This lets Claude Code-shaped clients (including
 * SuperAgent's AnthropicProvider) point at DashScope's Anthropic endpoint
 * and use Qwen as a drop-in for Claude.
 *
 * Why a dedicated subclass instead of reusing AnthropicProvider with a
 * base_url override (like the DeepSeek pattern documented in
 * AnthropicProvider's class docblock)?
 *
 *   1. Default model differs (`qwen3.7-max` vs Claude's `claude-opus-*`)
 *      so callers don't have to spell it on every request.
 *   2. Default base_url targets DashScope, not api.anthropic.com.
 *   3. Skips the Anthropic-OAuth "You are Claude Code" system-prompt
 *      guard — that's enforced server-side at api.anthropic.com and
 *      doesn't apply (and would actually break) on DashScope.
 *   4. Lets ProviderRegistry pick the right provider by tag without
 *      callers having to pass base_url + model + api_key triples every
 *      time.
 *
 * **TODO: verify base_url.** DashScope's exact Anthropic-protocol
 * endpoint URL has not been published in English docs as of 2026-05-22.
 * The default below is a best-guess based on the existing OpenAI-compat
 * endpoint pattern (`/compatible-mode/v1`). Operators MUST override via
 * config until verified:
 *
 *   - Verified for OpenAI-compat (QwenProvider): `dashscope.aliyuncs.com/compatible-mode/v1`
 *   - Suspected for Anthropic-compat (this class): `dashscope.aliyuncs.com/anthropic-mode/v1`
 *
 * If the suspected path 404s, try other URL patterns Alibaba uses:
 *   - `bailian.console.aliyun.com/anthropic/...`
 *   - `dashscope.aliyuncs.com/api/v1/services/anthropic/`
 *   - or check `~/.qwen/settings.json` after installing qwen-code v0.16+
 *     for an explicit anthropic-base-url field.
 *
 * Auth:
 *   - API key only (Qwen OAuth EOL'd 2026-04-15).
 *   - DASHSCOPE_API_KEY / QWEN_API_KEY env, or pass api_key directly.
 *
 * Use:
 *
 *     $agent = new Agent([
 *         'provider' => 'qwen-anthropic',
 *         'api_key'  => env('DASHSCOPE_API_KEY'),
 *         'model'    => 'qwen3.7-max',
 *         // base_url override if the default suspected URL doesn't work
 *     ]);
 */
final class QwenAnthropicProvider extends AnthropicProvider
{
    /**
     * Suspected DashScope Anthropic-compatible endpoint base URL.
     * Replace once Alibaba publishes the canonical path.
     */
    public const DEFAULT_BASE_URL = 'https://dashscope.aliyuncs.com/anthropic-mode/v1';

    public const DEFAULT_MODEL = 'qwen3.7-max';

    public function __construct(array $config)
    {
        // Inject defaults BEFORE delegating to AnthropicProvider so its
        // construction logic sees the Qwen-shaped values.
        $config['base_url'] ??= self::DEFAULT_BASE_URL;
        $config['model']    ??= self::DEFAULT_MODEL;
        $config['api_key']  ??= getenv('DASHSCOPE_API_KEY')
            ?: (getenv('QWEN_API_KEY') ?: null);

        // Force api_key auth — Qwen OAuth was discontinued 2026-04-15.
        // Callers passing access_token = treating Qwen like Claude Code
        // OAuth = won't work. Strip + force.
        if (isset($config['auth_mode']) && $config['auth_mode'] === 'oauth') {
            unset($config['auth_mode']);
        }
        unset($config['access_token']);

        parent::__construct($config);
    }

    public function name(): string
    {
        return 'qwen-anthropic';
    }
}
