<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsReasoningEffort;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * MiniMax — text chat via `/text/chatcompletion_v2`.
 *
 * Wire format is OpenAI + Anthropic dual-compatible. Non-text capabilities
 * (T2A, music, video, image, voice-cloning) live on distinct endpoints and
 * are wrapped by separate Capability implementations in later phases — only
 * the text endpoint is wired here.
 *
 * `X-GroupId` is an optional-but-recommended header that lets MiniMax route
 * the request to the group a key is attached to. SuperAgent carries it as
 * `$config['group_id']`; when absent, the header is simply omitted (for
 * accounts that don't use groups).
 *
 * M3 specifics surfaced here (launched 2026-06-01, MSA architecture, 1M ctx,
 * native multimodality):
 *   - **Interleaved thinking** is a single-model on/off/adaptive toggle,
 *     driven by the Anthropic-compatible `thinking: {type: ...}` field that
 *     GLM + DeepSeek V4 also use. Three accepted modes:
 *       enabled  — always think (complex reasoning, agentic, long-horizon)
 *       disabled — never think (lowest latency: chat, code completion)
 *       adaptive — model picks depth per turn (MiniMax's recommended default)
 *     Both thinking and non-thinking share the same per-token price.
 *   - Reasoning arrives as `delta.reasoning_content` and is surfaced as a
 *     `thinking` ContentBlock by the shared `parseSSEStream()` logic.
 *   - Native image + video input ride the standard OpenAI-style content
 *     parts (`image_url` / `video_url`) and pass straight through the
 *     encoder — no provider-specific handling needed here.
 *
 * Regions:
 *   - `intl` (default) → api.minimax.io    — global
 *   - `cn`             → api.minimaxi.com  — China mainland
 */
class MiniMaxProvider extends ChatCompletionsProvider implements SupportsThinking, SupportsReasoningEffort
{
    /**
     * Enable M3 thinking. MiniMax doesn't expose an explicit token budget on
     * this field yet — the server controls depth — so `$budgetTokens` is
     * advisory and we simply switch thinking on.
     */
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        return ['thinking' => ['type' => 'enabled']];
    }

    /**
     * Map the normalised effort dial onto M3's three thinking modes:
     *
     *   off                         → thinking disabled (fast path)
     *   adaptive                    → model decides depth (recommended)
     *   low / medium / high / max   → thinking enabled
     *
     * Unknown values return [] so a misconfigured caller can't poison the
     * request.
     */
    public function reasoningEffortFragment(string $effort): array
    {
        return match (strtolower(trim($effort))) {
            'off', 'disabled', 'none', 'false' => ['thinking' => ['type' => 'disabled']],
            'adaptive', 'auto' => ['thinking' => ['type' => 'adaptive']],
            'low', 'minimal', 'medium', 'mid', 'high', 'max', 'xhigh', 'highest', '' => ['thinking' => ['type' => 'enabled']],
            default => [],
        };
    }

    protected function providerName(): string
    {
        return 'minimax';
    }

    protected function defaultRegion(): string
    {
        return 'intl';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'intl' => 'https://api.minimax.io',
            'cn' => 'https://api.minimaxi.com',
            default => throw new ProviderException(
                "Unknown region '{$region}' for minimax (expected: intl, cn)",
                'minimax',
            ),
        };
    }

    protected function defaultModel(): string
    {
        return 'MiniMax-M3';
    }

    protected function chatCompletionsPath(): string
    {
        return 'v1/text/chatcompletion_v2';
    }

    protected function extraHeaders(array $config): array
    {
        return empty($config['group_id']) ? [] : ['X-GroupId' => (string) $config['group_id']];
    }

    /**
     * Direct knobs for callers who don't go through the features API:
     *
     *   $options['thinking']         — true | 'enabled' | 'disabled' | 'adaptive'
     *                                  | ['type' => 'enabled', ...]
     *   $options['reasoning_effort'] — 'off' | 'adaptive' | 'low'…'max'
     *
     * `reasoning_effort` wins over a bare `thinking` toggle when both are set
     * (it carries the more specific intent), matching DeepSeek's precedence.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    protected function customizeRequestBody(array &$body, array $options): void
    {
        if (isset($options['thinking']) && $options['thinking'] !== false) {
            $body['thinking'] = match (true) {
                is_array($options['thinking']) => $options['thinking'],
                is_string($options['thinking']) => ['type' => $options['thinking']],
                default => ['type' => 'enabled'],
            };
        }

        if (isset($options['reasoning_effort']) && is_string($options['reasoning_effort'])) {
            foreach ($this->reasoningEffortFragment($options['reasoning_effort']) as $k => $v) {
                $body[$k] = $v;
            }
        }
    }
}
