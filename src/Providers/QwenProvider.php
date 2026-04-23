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

    /**
     * `X-DashScope-UserAgent` lets Alibaba's backend attribute traffic
     * to a specific client family (qwen-code emits its own version
     * string here — see qwen-code's `provider/dashscope.ts:40-54`).
     * We send our package version so DashScope-side telemetry can
     * distinguish SuperAgent traffic for support / quota analysis.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    protected function extraHeaders(array $config): array
    {
        // `X-DashScope-CacheControl: enable` is the server-side toggle
        // for block-level prompt caching. Harmless when the request
        // body carries no `cache_control` markers (server just treats
        // the request as non-cached); required when the
        // DashScopeCacheControlAdapter has pinned markers on the
        // system msg / last tool / last streaming message.
        // See qwen-code `provider/dashscope.ts:40-54` for the same
        // "header always, markers opt-in via features" split.
        return [
            'X-DashScope-UserAgent'   => 'SuperAgent/' . self::agentVersion(),
            'X-DashScope-CacheControl' => 'enable',
        ];
    }

    /**
     * Two DashScope-specific niceties:
     *
     * 1. **Vision model auto-flag** (`vl_high_resolution_images: true`).
     *    qwen-code (`dashscope.ts:116-128`) detects vision-capable
     *    models by id prefix and turns this on automatically. Without
     *    it, large images get downsampled server-side, hurting OCR.
     *
     * 2. **Request metadata envelope** (`metadata: {sessionId, promptId,
     *    channel}`). Lets DashScope-side dashboards group requests by
     *    session and attribute them to a known client family.
     *    `channel` is hard-coded to `superagent` so the backend can
     *    distinguish us from qwen-code etc.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    protected function customizeRequestBody(array &$body, array $options): void
    {
        $model = (string) ($body['model'] ?? $this->model);
        if (self::isVisionModel($model)) {
            $body['vl_high_resolution_images'] = true;
        }

        $metadata = array_filter([
            'sessionId' => $options['session_id'] ?? null,
            'promptId'  => $options['prompt_id'] ?? null,
            'channel'   => 'superagent',
        ], static fn ($v) => $v !== null && $v !== '');
        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }
    }

    /**
     * Vision-capable Qwen model ids — match qwen-code's detection in
     * `dashscope.ts:116-128`. Kept as a static helper so unit tests
     * can hit the heuristic without constructing a provider.
     */
    public static function isVisionModel(string $modelId): bool
    {
        $id = strtolower($modelId);
        return str_starts_with($id, 'qwen-vl')
            || str_starts_with($id, 'qwen3-vl')
            || str_starts_with($id, 'qwen3.5-plus')
            || str_starts_with($id, 'qwen3-omni');
    }

    /**
     * Read SuperAgent version from composer.json so the
     * `X-DashScope-UserAgent` header tracks releases without us
     * hand-maintaining a constant. Cached per process — composer.json
     * doesn't change inside a request.
     */
    private static function agentVersion(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $composer = dirname(__DIR__, 2) . '/composer.json';
        if (is_readable($composer)) {
            $json = @json_decode((string) file_get_contents($composer), true);
            if (is_array($json) && !empty($json['version']) && is_string($json['version'])) {
                return $cached = (string) $json['version'];
            }
        }
        return $cached = 'dev';
    }
}
