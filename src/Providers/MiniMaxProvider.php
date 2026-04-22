<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;

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
 * Regions:
 *   - `intl` (default) → api.minimax.io    — global
 *   - `cn`             → api.minimaxi.com  — China mainland
 */
class MiniMaxProvider extends ChatCompletionsProvider
{
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
        return 'MiniMax-M2.7';
    }

    protected function chatCompletionsPath(): string
    {
        return 'v1/text/chatcompletion_v2';
    }

    protected function extraHeaders(array $config): array
    {
        return empty($config['group_id']) ? [] : ['X-GroupId' => (string) $config['group_id']];
    }
}
