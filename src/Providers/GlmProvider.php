<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * Z.AI / BigModel — GLM family (GLM-5, GLM-4.6, GLM-5V-Turbo).
 *
 * Wire format is OpenAI-compatible at `/chat/completions`, but the base URL
 * includes the `/api/paas/v4/` path prefix so the completions path is
 * relative (`chat/completions`, not `v1/chat/completions`).
 *
 * Native feature surfaced in this phase: `thinking: {type: enabled}` request
 * field. Caller opts in via `$options['thinking'] = true`, or (starting
 * Phase 3) via the generic `$options['features']['thinking']` spec routed
 * through `ThinkingAdapter`.
 *
 * Regions:
 *   - `intl` (default) → api.z.ai          — Z.AI international
 *   - `cn`             → open.bigmodel.cn  — BigModel China mainland
 */
class GlmProvider extends ChatCompletionsProvider implements SupportsThinking
{
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        // GLM supports `thinking: {type: "enabled"}`. It doesn't expose an
        // explicit budget field yet, so `$budgetTokens` is advisory — we
        // enable thinking and trust the server default.
        return ['thinking' => ['type' => 'enabled']];
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
        return 'glm-4.6';
    }

    /**
     * Base URL already contains `/api/paas/v4/`, so completions live at
     * `chat/completions` relative to it.
     */
    protected function chatCompletionsPath(): string
    {
        return 'chat/completions';
    }

    protected function customizeRequestBody(array &$body, array $options): void
    {
        // Native thinking field. `true` or `['type' => 'enabled', ...]` both work.
        if (isset($options['thinking']) && $options['thinking'] !== false) {
            $body['thinking'] = is_array($options['thinking'])
                ? $options['thinking']
                : ['type' => 'enabled'];
        }
    }
}
