<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsReasoningEffort;

/**
 * xAI — Grok family. Current lineup: grok-4.3 (flagship, 1M ctx), the
 * grok-4.20 reasoning / non-reasoning / multi-agent SKUs, grok-build-0.1,
 * plus grok-4 / grok-4-fast / grok-code-fast-1 / grok-3 / grok-3-mini.
 *
 * Wire format is OpenAI-compatible at `https://api.x.ai/v1/chat/completions`,
 * so this is a thin {@see ChatCompletionsProvider} subclass — tool calling,
 * streaming and the OpenAI tool schema (`formatTools()`) come from the base.
 *
 * Auth: `XAI_API_KEY` (or `GROK_API_KEY`), passed as a Bearer token.
 *
 * Reasoning: grok-4 reasons natively; grok-3-mini exposes a `reasoning_effort`
 * dial (`low` | `high`). The full grok-4 / grok-3 (non-mini) models do NOT
 * accept `reasoning_effort` and 400 if it is sent, so the effort fragment is
 * only emitted for ids that actually support it.
 */
class GrokProvider extends ChatCompletionsProvider implements SupportsReasoningEffort
{
    protected function providerName(): string
    {
        return 'grok';
    }

    protected function defaultRegion(): string
    {
        return 'default';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'default', 'us', 'intl' => 'https://api.x.ai',
            default => throw new ProviderException(
                "Unknown region '{$region}' for grok (expected: default). "
                . 'Pass an explicit base_url for a relay/proxy endpoint.',
                'grok',
            ),
        };
    }

    protected function defaultModel(): string
    {
        // grok-4.3 is xAI's recommended primary model for chat + coding.
        return 'grok-4.3';
    }

    /**
     * xAI accepts the OpenAI bearer plus the standard `XAI_API_KEY`. We also
     * honor `GROK_API_KEY` as an alias for ergonomics.
     *
     * @param array<string, mixed> $config
     */
    protected function resolveBearer(array $config): ?string
    {
        return $config['api_key']
            ?? ($_ENV['XAI_API_KEY'] ?? getenv('XAI_API_KEY') ?: null)
            ?? ($_ENV['GROK_API_KEY'] ?? getenv('GROK_API_KEY') ?: null);
    }

    /**
     * Only `grok-3-mini` (and the `-mini` reasoning SKUs) accept the
     * `reasoning_effort` field. grok-4 reasons unconditionally and rejects
     * the param; the non-mini grok-3 has no reasoning channel. Map the
     * SDK's effort tiers onto xAI's two-level `low` / `high`.
     */
    public function reasoningEffortFragment(string $effort): array
    {
        if (! $this->modelSupportsReasoningEffort($this->model)) {
            return [];
        }

        return match (strtolower(trim($effort))) {
            'off', 'disabled', 'none', 'false' => [],
            'low', 'minimal', 'medium', 'mid' => ['reasoning_effort' => 'low'],
            'high', 'max', 'xhigh', 'highest', '' => ['reasoning_effort' => 'high'],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    protected function customizeRequestBody(array &$body, array $options): void
    {
        if (isset($options['reasoning_effort']) && is_string($options['reasoning_effort'])) {
            foreach ($this->reasoningEffortFragment($options['reasoning_effort']) as $k => $v) {
                $body[$k] = $v;
            }
        }
    }

    private function modelSupportsReasoningEffort(string $model): bool
    {
        return str_contains(strtolower($model), 'mini');
    }
}
