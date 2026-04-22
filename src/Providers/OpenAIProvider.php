<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * OpenAI — `/v1/chat/completions` with the vendor's OAuth / Organization
 * extensions.
 *
 * Thin subclass of `ChatCompletionsProvider`. The wire protocol (body
 * assembly, SSE parsing, tool format, retry) lives in the base; this class
 * only owns the pieces that are truly OpenAI-specific:
 *
 *   - OAuth bearer mode: `auth_mode = 'oauth'` + `access_token` instead of
 *     `api_key`. Selected implicitly when `access_token` is present.
 *   - `OpenAI-Organization` header (optional).
 *   - `chatgpt-account-id` header (OAuth only, for Codex/ChatGPT subs).
 *   - Historical `getName()` accessor, kept for callers still using it.
 *
 * This rewrite preserves every OAuth / API-key behaviour that
 * `OpenAIProviderOAuthTest` pins, plus the default base URL and model id
 * that `tests/Compat/ProviderDefaultsLockdownTest` locks down.
 */
class OpenAIProvider extends ChatCompletionsProvider
{
    protected function providerName(): string
    {
        return 'openai';
    }

    protected function defaultRegion(): string
    {
        // OpenAI is not region-split the way the Asian vendors are. Pin to a
        // single synthetic region so the Compat tests' "default base URL"
        // behaviour keeps working.
        return 'default';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return 'https://api.openai.com';
    }

    protected function defaultModel(): string
    {
        return 'gpt-4o';
    }

    protected function resolveBearer(array $config): ?string
    {
        $authMode = $this->resolveAuthMode($config);
        return $authMode === 'oauth'
            ? ($config['access_token'] ?? null)
            : ($config['api_key'] ?? null);
    }

    protected function missingBearerMessage(array $config): string
    {
        return $this->resolveAuthMode($config) === 'oauth'
            ? 'OAuth access_token is required'
            : 'API key is required';
    }

    protected function extraHeaders(array $config): array
    {
        $headers = [];

        if (! empty($config['organization'])) {
            $headers['OpenAI-Organization'] = (string) $config['organization'];
        }

        if ($this->resolveAuthMode($config) === 'oauth' && ! empty($config['account_id'])) {
            $headers['chatgpt-account-id'] = (string) $config['account_id'];
        }

        return $headers;
    }

    /**
     * Inferred auth mode: explicit `auth_mode` wins; otherwise presence of
     * `access_token` flips us into OAuth. Matches the original OpenAIProvider
     * behaviour that `OpenAIProviderOAuthTest::test_auth_mode_inferred_from_access_token`
     * pins.
     *
     * @param array<string, mixed> $config
     */
    protected function resolveAuthMode(array $config): string
    {
        return $config['auth_mode'] ?? (isset($config['access_token']) ? 'oauth' : 'api_key');
    }

    // ── Historical accessors kept for backward compatibility ─────

    public function getName(): string
    {
        return 'openai';
    }

    /**
     * Legacy list — kept because external callers read it. The authoritative
     * model catalog lives in `resources/models.json`; this list is a hint for
     * tools that want a quick-and-dirty "known good" set.
     *
     * @return array<int, string>
     */
    public function getSupportedModels(): array
    {
        return [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo',
            'o1-preview',
            'o1-mini',
        ];
    }

    public function supportsStructuredOutput(): bool
    {
        return in_array($this->model, ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo'], true);
    }
}
