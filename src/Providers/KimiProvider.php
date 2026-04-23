<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Auth\DeviceIdentity;
use SuperAgent\Auth\KimiCodeCredentials;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsPromptCacheKey;
use SuperAgent\Providers\Capabilities\SupportsSwarm;
use SuperAgent\Providers\Capabilities\SupportsThinking;

/**
 * Moonshot Kimi — Platform API.
 *
 * Native provider for the Kimi K2.6 family (and predecessors). Speaks the
 * OpenAI `/chat/completions` wire shape at the base level; vendor-specific
 * capabilities (Agent Swarm, Skills, Files/extract, Batches, context caching)
 * land in later phases as separate Capability interface implementations.
 *
 * Regions:
 *   - `intl` (default) → api.moonshot.ai            — international, API key
 *   - `cn`             → api.moonshot.cn            — China mainland, API key
 *   - `code`           → api.kimi.com/coding/v1     — Kimi Code subscription,
 *                                                     OAuth bearer via
 *                                                     KimiCodeCredentials
 *
 * API keys on `intl`/`cn` are host-bound: an intl key cannot call the cn
 * endpoint and vice versa. SuperAgent's CredentialPool carries an optional
 * `region` tag so a single account can safely pool both key families.
 *
 * `code` region uses OAuth — see `src/Auth/KimiCodeCredentials.php`. When
 * the region is `code`, we consult the credential store for a valid
 * access token and fall back to `api_key` only as a secondary option.
 *
 * Device identification headers: Moonshot's backend inspects a family of
 * `X-Msh-*` headers (platform, device id, agent version, OS) for per-
 * install rate limiting. We send them on every Kimi request so the
 * backend can attribute our traffic consistently regardless of which
 * region we hit. See `DeviceIdentity::kimiHeaders()`.
 */
class KimiProvider extends ChatCompletionsProvider implements SupportsPromptCacheKey, SupportsSwarm, SupportsThinking
{
    /**
     * Kimi's session-level prompt cache is opt-in via `prompt_cache_key`.
     * Passing a stable session id lets Moonshot transparently cache the
     * shared prefix of requests that share the key. Usage is returned
     * via `prompt_tokens_details.cached_tokens` (new OpenAI shape) and
     * legacy `cached_tokens` (old shape) — both parsed by Usage.
     */
    public function promptCacheKeyFragment(string $sessionId): array
    {
        if ($sessionId === '') {
            return [];
        }
        return ['prompt_cache_key' => $sessionId];
    }

    /**
     * Kimi activates thinking on the SAME model via two request fields:
     *
     *   - `reasoning_effort`: "low" | "medium" | "high" (top-level field)
     *   - `thinking: {type: "enabled"}`               (top-level field;
     *     sent via OpenAI SDK's `extra_body` in Python, but over-the-wire
     *     it lands at the top level of the chat/completions payload)
     *
     * Reference: Moonshot's kimi-cli (packages/kosong/.../chat_provider/kimi.py)
     * uses `with_thinking(effort)` that sets both fields. The earlier
     * assumption that Kimi required a `kimi-k2-thinking-preview` model
     * variant was incorrect — Moonshot never published such a model id;
     * thinking is a per-request toggle on the standard K2 family.
     *
     * Budget→effort mapping (tuned against Moonshot's published effort
     * tiers; the caller passes a token budget which we bucket):
     *   - budget < 2000            → "low"
     *   - budget ≤ 8000 (default 4000 lands here) → "medium"
     *   - budget > 8000            → "high"
     *
     * If the caller passes 0 or negative, we still emit "low" + enabled —
     * the adapter only reaches this method when thinking is wanted.
     */
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        $effort = match (true) {
            $budgetTokens < 2000   => 'low',
            $budgetTokens <= 8000  => 'medium',
            default                => 'high',
        };

        return [
            'reasoning_effort' => $effort,
            'thinking' => ['type' => 'enabled'],
        ];
    }

    /**
     * Submit a Kimi K2.6 Agent Swarm job.
     *
     * NOTE: Moonshot has announced Agent Swarm (300 sub-agents / 4000 step
     * coordination) but the public REST schema is not yet published as of
     * the Phase-7 implementation date. The endpoint / body shape used here
     * is a best-effort structural placeholder — when Moonshot publishes the
     * official spec, the path + body keys below need validation and likely
     * minor adjustment. The callers (KimiSwarmTool, SwarmRouter) are
     * architecturally correct and will not need to change.
     *
     * Provisional endpoints (mark UNVERIFIED):
     *   POST  /v1/swarm/jobs                 — submit job
     *   GET   /v1/swarm/jobs/{id}            — poll status
     *   POST  /v1/swarm/jobs/{id}/cancel     — cancel
     *
     * @param array<string, mixed> $opts
     */
    public function submitSwarm(string $prompt, array $opts = []): JobHandle
    {
        $body = array_filter([
            'prompt' => $prompt,
            'model' => $opts['model'] ?? $this->model,
            'max_sub_agents' => $opts['max_sub_agents'] ?? null,
            'max_steps' => $opts['max_steps'] ?? null,
            'deliverable' => $opts['deliverable'] ?? null,
            'skills' => $opts['skills'] ?? null,
        ], static fn ($v) => $v !== null);

        $response = $this->client->post('v1/swarm/jobs', ['json' => $body]);
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw new ProviderException(
                'Kimi swarm submit returned no job id',
                $this->providerName(),
            );
        }

        return JobHandle::new(
            provider: $this->providerName(),
            jobId: (string) $decoded['id'],
            kind: 'swarm',
        );
    }

    public function poll(JobHandle $handle): JobStatus
    {
        $response = $this->client->get('v1/swarm/jobs/' . rawurlencode($handle->jobId));
        $decoded = json_decode((string) $response->getBody(), true);
        $status = (string) ($decoded['status'] ?? 'unknown');

        return match (strtolower($status)) {
            'completed', 'done', 'success'       => JobStatus::Done,
            'failed', 'error'                    => JobStatus::Failed,
            'cancelled', 'canceled'              => JobStatus::Canceled,
            'pending', 'queued', 'validating'    => JobStatus::Pending,
            default                              => JobStatus::Running,
        };
    }

    public function fetch(JobHandle $handle): mixed
    {
        $response = $this->client->get('v1/swarm/jobs/' . rawurlencode($handle->jobId));
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            return null;
        }
        // Deliverable shape depends on the job kind — callers inspect it.
        return $decoded['result'] ?? $decoded['output'] ?? $decoded;
    }

    public function cancel(JobHandle $handle): bool
    {
        try {
            $this->client->post('v1/swarm/jobs/' . rawurlencode($handle->jobId) . '/cancel');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function providerName(): string
    {
        return 'kimi';
    }

    protected function defaultRegion(): string
    {
        return 'intl';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'intl' => 'https://api.moonshot.ai',
            'cn'   => 'https://api.moonshot.cn',
            'code' => 'https://api.kimi.com/coding',  // `/v1` added by chatCompletionsPath/modelsPath
            default => throw new ProviderException(
                "Unknown region '{$region}' for kimi (expected: intl, cn, code)",
                'kimi',
            ),
        };
    }

    protected function defaultModel(): string
    {
        return 'kimi-k2-6';
    }

    /**
     * On `code` region, OAuth access token wins. Fall back to api_key
     * for any region so existing KIMI_API_KEY users keep working even
     * after this PR lands.
     */
    protected function resolveBearer(array $config): ?string
    {
        $region = (string) ($config['region'] ?? $this->defaultRegion());
        if ($region === 'code') {
            $oauth = new KimiCodeCredentials();
            $token = $oauth->currentAccessToken();
            if ($token !== null && $token !== '') {
                return $token;
            }
            // Permit explicit override via $config['access_token'] for
            // callers that manage OAuth themselves; otherwise fall through
            // to api_key and let the base class report "missing bearer".
            if (!empty($config['access_token'])) {
                return (string) $config['access_token'];
            }
        }
        return parent::resolveBearer($config);
    }

    protected function missingBearerMessage(array $config): string
    {
        $region = (string) ($config['region'] ?? $this->defaultRegion());
        if ($region === 'code') {
            return 'Kimi Code requires OAuth login (region=code). No credentials found at '
                . '~/.superagent/credentials/kimi-code.json — run `superagent login kimi-code` '
                . 'or pass an api_key/access_token explicitly.';
        }
        return 'KIMI_API_KEY is required';
    }

    /**
     * Serialize tools for the Moonshot wire. Two shapes are emitted:
     *
     *   - Normal tools →  {"type": "function", "function": {name, description, parameters}}
     *   - Server-hosted builtins (name starts with `$`) →
     *                     {"type": "builtin_function", "function": {"name": "$xxx"}}
     *
     * The `$`-prefix convention mirrors Moonshot's own kimi-cli (see
     * `packages/kosong/src/kosong/chat_provider/kimi.py`). Subclasses
     * of `KimiServerBuiltinTool` already hard-code a `$` name; this
     * override accepts ANY `Tool` whose name starts with `$` so users
     * can opt in without extending our base class.
     */
    protected function convertTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof \SuperAgent\Contracts\ToolInterface) {
                continue;
            }
            $name = $tool->name();
            if ($name !== '' && $name[0] === '$') {
                $out[] = [
                    'type' => 'builtin_function',
                    'function' => ['name' => $name],
                ];
                continue;
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool->description(),
                    'parameters' => $tool->inputSchema(),
                ],
            ];
        }
        return $out;
    }

    /**
     * All Kimi regions get the Moonshot device identification headers.
     * See `DeviceIdentity::kimiHeaders()` for the exact fields.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    protected function extraHeaders(array $config): array
    {
        return DeviceIdentity::kimiHeaders();
    }
}
