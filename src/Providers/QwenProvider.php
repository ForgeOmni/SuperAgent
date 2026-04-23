<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * Alibaba Qwen — DashScope OpenAI-compatible API.
 *
 * Default Qwen path. Speaks the standard `chat/completions` wire shape
 * against `<region-host>/compatible-mode/v1/chat/completions`. This is
 * what Alibaba's own qwen-code CLI uses **exclusively** — see
 * `packages/core/src/core/openaiContentGenerator/constants.ts:5` in the
 * upstream repo:
 *
 *   DEFAULT_DASHSCOPE_BASE_URL = 'https://dashscope.aliyuncs.com/compatible-mode/v1'
 *
 * For the legacy `text-generation/generation` body shape (which exposes
 * `parameters.thinking_budget` — a knob the OpenAI-compatible endpoint
 * does NOT surface), opt into `QwenNativeProvider` via
 * `ProviderRegistry::create('qwen-native', $config)`.
 *
 * Regions:
 *   - `intl` (default) → dashscope-intl.aliyuncs.com — Singapore
 *   - `us`             → dashscope-us.aliyuncs.com    — Virginia
 *   - `cn`             → dashscope.aliyuncs.com       — Beijing
 *   - `hk`             → cn-hongkong.dashscope.aliyuncs.com
 *
 * Thinking on Qwen3 / Qwen3.6 is opt-in via `extra_body.enable_thinking`
 * (boolean only — there is NO `thinking_budget` on this endpoint).
 * `thinkingRequestFragment()` emits the correct shape; the budget
 * argument is accepted for interface compatibility but ignored, with a
 * one-shot warning when `SUPERAGENT_DEBUG=1` so callers migrating from
 * the native endpoint notice.
 */
class QwenProvider extends ChatCompletionsProvider implements SupportsThinking
{
    private static bool $thinkingBudgetWarned = false;

    public function thinkingRequestFragment(int $budgetTokens): array
    {
        // DashScope's OpenAI-compatible endpoint accepts `enable_thinking` as
        // a top-level boolean (delivered through OpenAI SDK's `extra_body`,
        // which on the wire just lands at the body root). There is NO
        // `thinking_budget` on this path — qwen-code grep shows zero hits.
        //
        // We accept the budget arg for interface compatibility but only
        // surface a debug warning the first time a positive budget shows up
        // so callers migrating from QwenNativeProvider know it's a no-op.
        if ($budgetTokens > 0 && getenv('SUPERAGENT_DEBUG') === '1' && ! self::$thinkingBudgetWarned) {
            self::$thinkingBudgetWarned = true;
            error_log(
                '[SuperAgent][qwen] thinking_budget is ignored on the OpenAI-compatible '
                . 'DashScope endpoint. Switch to provider=qwen-native for budget control, '
                . 'or omit `budget` from the thinking spec.'
            );
        }

        // Emit at top level — `FeatureAdapter::merge()` deep-merges into the
        // outgoing body so `enable_thinking: true` lands at body root, where
        // DashScope's compatible endpoint accepts it.
        return ['enable_thinking' => true];
    }

    protected function providerName(): string
    {
        return 'qwen';
    }

    protected function defaultRegion(): string
    {
        return 'intl';
    }

    protected function regionToBaseUrl(string $region): string
    {
        // Region map matches QwenNativeProvider so users switching between
        // the two providers don't have to re-pick a region. The `/compatible-mode/v1`
        // suffix lives in the base URL because chatCompletionsPath() returns
        // just `chat/completions`.
        $host = match ($region) {
            'intl' => 'https://dashscope-intl.aliyuncs.com',
            'us'   => 'https://dashscope-us.aliyuncs.com',
            'cn'   => 'https://dashscope.aliyuncs.com',
            'hk'   => 'https://cn-hongkong.dashscope.aliyuncs.com',
            default => throw new ProviderException(
                "Unknown region '{$region}' for qwen (expected: intl, us, cn, hk)",
                'qwen',
            ),
        };
        return $host . '/compatible-mode/v1';
    }

    protected function defaultModel(): string
    {
        return 'qwen3.6-max-preview';
    }

    /**
     * Base path is `/compatible-mode/v1` already; only `chat/completions`
     * needs to be appended.
     */
    protected function chatCompletionsPath(): string
    {
        return 'chat/completions';
    }

    protected function missingBearerMessage(array $config): string
    {
        return 'QWEN_API_KEY (or DASHSCOPE_API_KEY) is required';
    }
}
