<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\Capabilities\SupportsReasoningEffort;
use SuperAgent\Traits\MuseSparkSurfaceTrait;

/**
 * Meta Model API — Muse Spark family, via `POST /v1/chat/completions`
 * at `https://api.meta.ai`.
 *
 * Meta ships three request formats against the same models and billing:
 * a Responses API, this OpenAI-compatible Chat Completions API, and an
 * Anthropic-compatible Messages API. This provider wires the Chat
 * Completions route; {@see MetaResponsesProvider} (`provider: 'meta-responses'`)
 * speaks the Responses API, which is the one to reach for in agentic loops —
 * only it can replay reasoning across turns. The Anthropic route is
 * reachable without new code by
 * pointing `provider=anthropic` at `base_url=https://api.meta.ai` with a
 * `muse-spark-*` model, the same trick the DeepSeek Anthropic route uses.
 *
 * Auth is a bearer token. Meta's own docs name the env var `MODEL_API_KEY`;
 * SuperAgent reads `META_API_KEY` first (consistent with every other
 * provider here) and falls back to `MODEL_API_KEY`.
 *
 * Muse Spark specifics that shape the wire and are handled here:
 *
 *   - **`max_completion_tokens`, not `max_tokens`.** Muse Spark always
 *     reasons, and the legacy field is not the one Meta documents — the
 *     base class hook {@see completionTokenParam()} switches it.
 *   - **Reasoning cannot be disabled.** `reasoning_effort` accepts
 *     `minimal | low | medium | high | xhigh` (plus `max` on 1.3), and
 *     `none` returns **400**. The dial therefore floors at `minimal`
 *     rather than emitting an off switch, and `max` is downgraded to
 *     `xhigh` on every id that lacks the top tier — 1.1, 1.2 and every
 *     `-contributor` id (`max` is Standard-tier 1.3 only).
 *   - **`developer` outranks `system`.** Meta accepts `system` for OpenAI
 *     compatibility but documents `developer` as the highest-precedence
 *     instruction channel, so the hoisted system prompt is re-roled.
 *   - **Unsupported OpenAI parameters are stripped**: `stop`, `logprobs`,
 *     `logit_bias`, `prediction`, `modalities`, `audio`,
 *     `web_search_options`, and `n` > 1 — each is a 400. They are removed
 *     after `extra_body` merges, so a caller carrying an OpenAI-shaped
 *     payload across providers doesn't get a hard failure here.
 *   - **Search grounding** is a server-side tool (`{"type": "web_search"}`)
 *     billed per query, not a request flag; `options['grounding']` /
 *     `options['web_search']` appends it alongside function tools.
 *   - **`prompt_cache_key`** groups related requests for cache hits and
 *     **`safety_identifier`** is the privacy-preserving end-user id
 *     (≤ 64 chars); both are first-class options here.
 *
 * Multimodal input (image / video / audio / PDF) rides the standard
 * OpenAI-style content parts and needs no provider-specific handling.
 *
 * Contributor-tier ids (`muse-spark-1.3-contributor`) are the same models
 * at ~12x lower cost **in exchange for Meta training on your prompts and
 * completions** — never route traffic there implicitly; a caller has to
 * name the id.
 */
class MetaProvider extends ChatCompletionsProvider implements SupportsReasoningEffort
{
    use MuseSparkSurfaceTrait;

    /**
     * OpenAI parameters the Model API rejects outright (HTTP 400).
     * Stripped from the final body rather than forwarded.
     */
    private const UNSUPPORTED_PARAMS = [
        'stop',
        'logprobs',
        'top_logprobs',
        'logit_bias',
        'prediction',
        'modalities',
        'audio',
        'web_search_options',
    ];

    /**
     * Uniform effort dial ({@see SupportsReasoningEffort}).
     *
     * Muse Spark reasons unconditionally: there is no `none` tier and
     * sending one is a 400, so "off" floors at `minimal` (the cheapest
     * real tier) instead of trying to switch reasoning off. `max` only
     * exists on 1.3 — older ids get `xhigh`.
     *
     * @return array<string, mixed>
     */
    public function reasoningEffortFragment(string $effort): array
    {
        $tier = $this->museSparkEffortTier($effort, $this->model);

        return $tier === null ? [] : ['reasoning_effort' => $tier];
    }

    protected function providerName(): string
    {
        return 'meta';
    }

    protected function defaultRegion(): string
    {
        return 'default';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'default', 'us', '' => 'https://api.meta.ai',
            default => throw new ProviderException(
                "Unknown region '{$region}' for meta (expected: default)",
                'meta',
            ),
        };
    }

    protected function defaultModel(): string
    {
        // Muse Spark 1.3 (2026-09-02) — MSL's agentic coding flagship:
        // 1M ctx, text/image/video/audio/PDF in, full effort dial.
        return 'muse-spark-1.3';
    }

    protected function chatCompletionsPath(): string
    {
        return 'v1/chat/completions';
    }

    /**
     * Muse Spark always reasons, and the reasoning channel shares the
     * completion budget — Meta documents `max_completion_tokens` and the
     * legacy `max_tokens` is not part of the supported surface.
     */
    protected function completionTokenParam(): string
    {
        return 'max_completion_tokens';
    }

    /**
     * Meta's docs name the env var `MODEL_API_KEY`; `META_API_KEY` keeps
     * the naming consistent with the rest of SuperAgent and wins when both
     * are set.
     *
     * @param array<string, mixed> $config
     */
    protected function resolveBearer(array $config): ?string
    {
        return $this->resolveMetaBearer($config);
    }

    protected function missingBearerMessage(array $config): string
    {
        return $this->missingMetaBearerMessage();
    }

    /**
     * Direct knobs for callers who don't go through the features API:
     *
     *   $options['reasoning_effort']   — 'off'…'max' (floors at `minimal`)
     *   $options['grounding']          — true → server-side web_search tool
     *   $options['web_search']         — alias of the above
     *   $options['prompt_cache_key']   — cache-affinity group key
     *   $options['safety_identifier']  — stable pseudonymous end-user id
     *
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

        if (! empty($options['grounding']) || ! empty($options['web_search'])) {
            $body['tools'] = array_merge($body['tools'] ?? [], [['type' => 'web_search']]);
            // A server tool alone still needs a choice the API accepts; the
            // base only sets tool_choice when function tools are present.
            $body['tool_choice'] ??= $options['tool_choice'] ?? 'auto';
        }

        foreach (['prompt_cache_key', 'safety_identifier'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                $body[$key] = $options[$key];
            }
        }
    }

    /**
     * Post-process the assembled body: re-role the system prompt onto
     * `developer` and drop parameters the Model API rejects.
     *
     * Runs after the base class has merged `extra_body`, so a cross-provider
     * payload carrying e.g. `stop` or `logit_bias` is sanitised rather than
     * turned into a 400.
     *
     * @param  array<int, mixed>    $messages
     * @param  array<int, mixed>    $tools
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function buildRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        $body = parent::buildRequestBody($messages, $tools, $systemPrompt, $options);

        if (isset($body['messages']) && is_array($body['messages'])) {
            foreach ($body['messages'] as $i => $message) {
                if (($message['role'] ?? null) === 'system') {
                    $body['messages'][$i]['role'] = 'developer';
                }
            }
        }

        foreach (self::UNSUPPORTED_PARAMS as $param) {
            unset($body[$param]);
        }

        // `n` is accepted only as 1; anything else is a 400.
        if (isset($body['n']) && (int) $body['n'] !== 1) {
            unset($body['n']);
        }

        return $body;
    }
}
