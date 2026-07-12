<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsReasoningEffort;

/**
 * xAI — Grok family. Current lineup: grok-4.5 (flagship, 500K ctx),
 * grok-4.3 (previous flagship, 1M ctx), the grok-4.20 reasoning /
 * non-reasoning / multi-agent SKUs, grok-build-0.1, plus grok-4 /
 * grok-4-fast / grok-code-fast-1 / grok-3 / grok-3-mini.
 *
 * Wire format is OpenAI-compatible at `https://api.x.ai/v1/chat/completions`,
 * so this is a thin {@see ChatCompletionsProvider} subclass — tool calling,
 * streaming and the OpenAI tool schema (`formatTools()`) come from the base.
 *
 * Auth: `XAI_API_KEY` (or `GROK_API_KEY`), passed as a Bearer token.
 *
 * Reasoning: grok-4.5 reasons unconditionally (no off switch) and takes the
 * three-level `reasoning_effort` dial (`low` | `medium` | `high`, server
 * default `high`); grok-3-mini exposes the older two-level dial
 * (`low` | `high`). grok-4 / grok-4.3 / grok-3 (non-mini) do NOT accept
 * `reasoning_effort` and 400 if it is sent, so the effort fragment is only
 * emitted for ids that actually support it.
 *
 * Prompt caching: xAI recommends pinning a conversation to a server for
 * reliable cache hits. On Chat Completions that is the `x-grok-conv-id`
 * header — pass `conversation_id` (or `prompt_cache_key`) in the provider
 * config and it is sent on every request.
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
        // grok-4.5 is xAI's recommended primary model for chat + coding
        // (also the default of Grok Build).
        return 'grok-4.5';
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
     * `grok-4.5` and `grok-3-mini` (plus the `-mini` reasoning SKUs) accept
     * the `reasoning_effort` field. grok-4 / grok-4.3 reason unconditionally
     * and reject the param; the non-mini grok-3 has no reasoning channel —
     * for those the fragment is empty.
     *
     * grok-4.5 takes the three-level dial (`low` | `medium` | `high`,
     * server default `high`) and its reasoning cannot be disabled, so `off`
     * maps to "send nothing". The mini SKUs keep xAI's older two-level
     * `low` / `high` mapping.
     */
    public function reasoningEffortFragment(string $effort): array
    {
        $tier = strtolower(trim($effort));

        if ($this->isGrok45($this->model)) {
            return match ($tier) {
                'off', 'disabled', 'none', 'false' => [],
                'low', 'minimal' => ['reasoning_effort' => 'low'],
                'medium', 'mid' => ['reasoning_effort' => 'medium'],
                'high', 'max', 'xhigh', 'highest', '' => ['reasoning_effort' => 'high'],
                default => [],
            };
        }

        if (! $this->modelSupportsReasoningEffort($this->model)) {
            return [];
        }

        return match ($tier) {
            'off', 'disabled', 'none', 'false' => [],
            'low', 'minimal', 'medium', 'mid' => ['reasoning_effort' => 'low'],
            'high', 'max', 'xhigh', 'highest', '' => ['reasoning_effort' => 'high'],
            default => [],
        };
    }

    /**
     * Grok 4.5 cache routing — xAI recommends pinning the conversation to a
     * server via the `x-grok-conv-id` header on Chat Completions so prompt
     * cache hits are reliable ($0.50/M cached vs $2/M fresh input).
     *
     * @param array<string, mixed> $config
     */
    protected function extraHeaders(array $config): array
    {
        $headers = [];

        $convId = $config['conversation_id'] ?? $config['prompt_cache_key'] ?? null;
        if (is_string($convId) && $convId !== '') {
            $headers['x-grok-conv-id'] = $convId;
        }

        return $headers;
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
        $m = strtolower($model);
        return $this->isGrok45($m) || str_contains($m, 'mini');
    }

    private function isGrok45(string $model): bool
    {
        return str_starts_with(strtolower($model), 'grok-4.5');
    }
}
