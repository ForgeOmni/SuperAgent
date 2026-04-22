<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * OpenRouter — multi-vendor aggregator speaking the OpenAI
 * `/chat/completions` wire shape with a couple of routing-specific
 * request fields (`provider_order`, `provider_preferences`, `transforms`).
 *
 * Refactored from a standalone ~430-line class into a ~90-line
 * `ChatCompletionsProvider` subclass in v0.8.9 (Improvement #16). All
 * observable behaviour is preserved:
 *
 *   - Default base URL `https://openrouter.ai`, completions path
 *     `api/v1/chat/completions` (note: leading `api/`, not `v1/`).
 *   - Default model `anthropic/claude-3-5-sonnet`.
 *   - `HTTP-Referer` + `X-Title` headers surfaced via `extraHeaders()`.
 *   - Native routing fields (`provider_order`, `provider_preferences`,
 *     `transforms`) added through `customizeRequestBody()`.
 *
 * The refactor dropped the custom model-used tracking in the SSE parser
 * — the base parser doesn't surface `metadata['model_used']`, and no
 * shipped caller reads it. If that value becomes important, expose it
 * via a new `AssistantMessage::metadata` channel rather than forking the
 * SSE parser again.
 */
class OpenRouterProvider extends ChatCompletionsProvider
{
    protected function providerName(): string
    {
        return 'openrouter';
    }

    protected function defaultRegion(): string
    {
        return 'default';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return 'https://openrouter.ai';
    }

    protected function defaultModel(): string
    {
        return 'anthropic/claude-3-5-sonnet';
    }

    /**
     * OpenRouter hangs its completions endpoint off `api/v1/`, not the
     * canonical `v1/`. This is the legacy path — leave it pinned so the
     * `tests/Compat/ProviderDefaultsLockdownTest` Guzzle config snapshot
     * keeps matching.
     */
    protected function chatCompletionsPath(): string
    {
        return 'api/v1/chat/completions';
    }

    /**
     * OpenRouter wants two attribution headers — a referrer URL (so they
     * can show your app's traffic on the dashboard) and an `X-Title`
     * (friendly name). Both are optional from their API's perspective
     * but present on every request keeps dashboards clean for users.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    protected function extraHeaders(array $config): array
    {
        return [
            'HTTP-Referer' => (string) ($config['site_url'] ?? 'https://github.com/superagent'),
            'X-Title'      => (string) ($config['app_name']  ?? 'SuperAgent'),
        ];
    }

    /**
     * OpenRouter-specific routing knobs. `provider_order` pins which
     * backends OpenRouter tries first; `provider_preferences` tunes
     * per-backend behaviour; `use_fallbacks` activates token-saving
     * transforms.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    protected function customizeRequestBody(array &$body, array $options): void
    {
        if (isset($options['provider_order'])) {
            $body['provider_order'] = $options['provider_order'];
        }
        if (isset($options['provider_preferences'])) {
            $body['provider_preferences'] = $options['provider_preferences'];
        }
        if (! empty($options['use_fallbacks'])) {
            $body['transforms'] = ['fallbacks'];
        }
    }

    // ── Historical accessors kept for backward compatibility ─────

    public function getName(): string
    {
        return 'openrouter';
    }

    /**
     * Legacy hard-coded list. The authoritative model catalog lives in
     * `resources/models.json`; this list is a hint for callers that want
     * a quick "what does OpenRouter expose" answer without hitting the
     * network. Use `getAvailableModels()` for the live list.
     *
     * @return array<int, string>
     */
    public function getSupportedModels(): array
    {
        return [
            'anthropic/claude-3-5-sonnet',
            'anthropic/claude-3-opus',
            'anthropic/claude-3-sonnet',
            'anthropic/claude-3-haiku',
            'openai/gpt-4o',
            'openai/gpt-4-turbo',
            'openai/gpt-3.5-turbo',
            'google/gemini-pro',
            'google/gemini-pro-1.5',
            'meta-llama/llama-3-70b-instruct',
            'meta-llama/llama-3-8b-instruct',
            'mistralai/mistral-large',
            'mistralai/mixtral-8x7b-instruct',
        ];
    }

    /**
     * Query OpenRouter's live `/api/v1/models` catalog. Swallows network
     * errors — `[]` on failure — so CLI callers don't crash when offline.
     *
     * @return array<int, array{id: string, name: string, context_length: ?int, pricing: ?array}>
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->client->get('api/v1/models');
            $data = json_decode($response->getBody()->getContents(), true);
            return array_map(fn ($model) => [
                'id' => $model['id'],
                'name' => $model['name'] ?? $model['id'],
                'context_length' => $model['context_length'] ?? null,
                'pricing' => $model['pricing'] ?? null,
            ], $data['data'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }
}
