<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * DeepSeek — V4 family (deepseek-v4-pro / deepseek-v4-flash) and the
 * legacy V3 / R1 ids that retire 2026-07-24.
 *
 * Wire format is OpenAI-compatible at `/v1/chat/completions`. The same
 * endpoint exposes an Anthropic-compatible mode at `/anthropic/...` —
 * callers who need that route configure `provider=anthropic` with
 * `base_url=https://api.deepseek.com/anthropic` instead; this provider
 * sticks to the OpenAI shape.
 *
 * V4 specifics surfaced here:
 *   - Thinking / Non-Thinking are a single-model toggle. We enable it
 *     via `thinking: {type: enabled}` (the same shape Anthropic +
 *     GLM use); DeepSeek's server tolerates the field on V4 and ignores
 *     it on V3 / R1 (R1 is always-thinking; V3 is never-thinking).
 *   - Reasoning chain arrives as `delta.reasoning_content` — handled in
 *     `ChatCompletionsProvider::parseSSEStream()` shared logic, which
 *     emits a separate `thinking` ContentBlock at end-of-stream.
 *   - Context cache: automatic, per-account, no opt-in field. Cache
 *     reads come back as `prompt_tokens_details.cached_tokens` (the
 *     standard OpenAI-style shape) — base parser already plumbs that
 *     into `Usage::cacheReadInputTokens`, and `CostCalculator` applies
 *     the 1/10 read price.
 *   - Beta endpoint (`https://api.deepseek.com/beta`) exposes FIM /
 *     prefix completions for code use cases. Opt in via
 *     `region='beta'`. Same auth, same payload shape — only the base
 *     URL changes — so this provider just maps the region.
 */
class DeepSeekProvider extends ChatCompletionsProvider implements SupportsThinking
{
    /**
     * V4 wires thinking through the same `thinking: {type: enabled}` field
     * Anthropic and GLM use. DeepSeek's docs don't expose an explicit token
     * budget yet — the server controls it server-side based on the model
     * tier (V4-Pro thinks more aggressively than V4-Flash). Budget is
     * advisory; we still pass `enabled` so the model emits its
     * reasoning_content channel for callers that want it.
     */
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        return ['thinking' => ['type' => 'enabled']];
    }

    protected function providerName(): string
    {
        return 'deepseek';
    }

    protected function defaultRegion(): string
    {
        return 'default';
    }

    /**
     * Three regions:
     *   - `default` — production OpenAI-compat endpoint
     *   - `beta`    — same auth, exposes FIM / prefix completions
     *                 (`/beta/completions`) on top of the standard chat path
     *   - `cn`      — kept as alias of default; DeepSeek serves a single
     *                 global endpoint as of V4. Reserved if they split later.
     */
    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'default', 'cn' => 'https://api.deepseek.com',
            'beta'          => 'https://api.deepseek.com/beta',
            default => throw new ProviderException(
                "Unknown region '{$region}' for deepseek (expected: default, beta)",
                'deepseek',
            ),
        };
    }

    /**
     * Default to V4-Flash. V4-Pro is more capable but also ~4x the cost;
     * Flash matches the price/quality the legacy `deepseek-chat` users
     * already paid for and is what DeepSeek currently routes the retired
     * `deepseek-chat` / `deepseek-reasoner` aliases to.
     */
    protected function defaultModel(): string
    {
        return 'deepseek-v4-flash';
    }

    protected function customizeRequestBody(array &$body, array $options): void
    {
        // Direct `thinking` knob — same shape FeatureDispatcher would
        // produce, but exposed as a top-level option for callers who
        // don't go through the features API.
        if (isset($options['thinking']) && $options['thinking'] !== false) {
            $body['thinking'] = is_array($options['thinking'])
                ? $options['thinking']
                : ['type' => 'enabled'];
        }
    }
}
