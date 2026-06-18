<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsReasoningEffort;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * Z.AI / BigModel — GLM family (GLM-5.2, GLM-5, GLM-4.x, GLM-5V-Turbo).
 *
 * Wire format is OpenAI-compatible at `/chat/completions`, but the base URL
 * includes the `/api/paas/v4/` path prefix so the completions path is
 * relative (`chat/completions`, not `v1/chat/completions`).
 *
 * Native reasoning controls:
 *   - `thinking: {type: enabled|disabled}` — binary on/off, opted into via
 *     `$options['thinking'] = true` or the generic `features.thinking` spec.
 *   - `reasoning_effort: "high"|"max"` — GLM-5.2's effort dial, surfaced
 *     through `SupportsReasoningEffort`. Streamed reasoning arrives as
 *     `delta.reasoning_content` and is captured by the base class.
 *
 * Regions:
 *   - `intl` (default) → api.z.ai          — Z.AI international
 *   - `cn`             → open.bigmodel.cn  — BigModel China mainland
 */
class GlmProvider extends ChatCompletionsProvider implements SupportsThinking, SupportsReasoningEffort
{
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        // GLM supports `thinking: {type: "enabled"}`. It doesn't expose an
        // explicit budget field yet, so `$budgetTokens` is advisory — we
        // enable thinking and trust the server default.
        return ['thinking' => ['type' => 'enabled']];
    }

    /**
     * GLM-5.2 effort dial. The OpenAI-compat endpoint accepts top-level
     * `reasoning_effort` paired with `thinking: {type: enabled}`; "off"
     * disables thinking outright. Unknown values return [] so a
     * misconfigured caller never poisons the request.
     *
     * @return array<string, mixed>
     */
    public function reasoningEffortFragment(string $effort): array
    {
        return match (strtolower(trim($effort))) {
            'off', 'disabled', 'none', 'false' => ['thinking' => ['type' => 'disabled']],
            'low', 'minimal', 'medium', 'mid', 'high', '' => ['reasoning_effort' => 'high', 'thinking' => ['type' => 'enabled']],
            'max', 'xhigh', 'highest' => ['reasoning_effort' => 'max', 'thinking' => ['type' => 'enabled']],
            default => [],
        };
    }

    protected function providerName(): string
    {
        return 'glm';
    }

    protected function defaultRegion(): string
    {
        return 'intl';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'intl' => 'https://api.z.ai/api/paas/v4',
            'cn' => 'https://open.bigmodel.cn/api/paas/v4',
            default => throw new ProviderException(
                "Unknown region '{$region}' for glm (expected: intl, cn)",
                'glm',
            ),
        };
    }

    protected function defaultModel(): string
    {
        return 'glm-5.2';
    }

    /**
     * Base URL already contains `/api/paas/v4/`, so completions live at
     * `chat/completions` relative to it.
     */
    protected function chatCompletionsPath(): string
    {
        return 'chat/completions';
    }

    /**
     * Direct knobs for callers who don't go through the features API:
     *
     *   $options['thinking']         — true | 'enabled' | 'disabled'
     *                                  | ['type' => 'enabled', ...]
     *   $options['reasoning_effort'] — 'off' | 'low'…'high' | 'max'
     *
     * `reasoning_effort` wins over a bare `thinking` toggle when both are set
     * (it carries the more specific intent), matching DeepSeek / MiniMax.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    protected function customizeRequestBody(array &$body, array $options): void
    {
        // Native thinking field. `true` / 'enabled' / ['type' => 'enabled', ...] all work.
        if (isset($options['thinking']) && $options['thinking'] !== false) {
            $body['thinking'] = match (true) {
                is_array($options['thinking']) => $options['thinking'],
                is_string($options['thinking']) => ['type' => $options['thinking']],
                default => ['type' => 'enabled'],
            };
        }

        // GLM-5.2 effort dial — deep-merges `reasoning_effort` + `thinking`.
        if (isset($options['reasoning_effort']) && is_string($options['reasoning_effort'])) {
            foreach ($this->reasoningEffortFragment($options['reasoning_effort']) as $k => $v) {
                $body[$k] = $v;
            }
        }
    }
}
