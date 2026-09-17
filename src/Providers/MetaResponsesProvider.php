<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Exceptions\FeatureNotSupportedException;
use SuperAgent\Traits\MuseSparkSurfaceTrait;

/**
 * Meta Model API — **Responses** route (`POST /v1/responses` at
 * `https://api.meta.ai`). Sibling of {@see MetaProvider}, which speaks the
 * Chat Completions route against the same models and the same billing.
 *
 * ## Why this route exists
 *
 * Reasoning. On Chat Completions, Muse Spark's chain of thought is thrown
 * away at the end of every turn — the next request starts cold. The
 * Responses API is the only route that carries reasoning **across** turns,
 * which is what makes a multi-step agent loop behave. Meta's own guidance
 * is Chat Completions for one-shot calls, Responses for agentic work; this
 * provider is the latter.
 *
 * Two ways to carry it, and they are mutually exclusive:
 *
 *   1. **Encrypted replay (stateless, recommended).** `store: false` plus
 *      `include: ["reasoning.encrypted_content"]`. The client resends the
 *      whole conversation each turn and the reasoning rides along as an
 *      opaque blob. Nothing is retained server-side. Set
 *      `options['reasoning_replay'] => true` and this provider wires all
 *      of it, including forcing `store` off.
 *   2. **Server-managed state.** `previous_response_id` chains turns and
 *      the server reconstructs context; you send only the new input. The
 *      base class already tracks the last response id, so this is the
 *      default behaviour of a repeated `chat()` on one instance.
 *
 * Meta rejects a request that carries `include` *and* `previous_response_id`
 * together, so asking for replay drops the chaining id rather than letting
 * the call 400.
 *
 * ## Divergences from OpenAI's Responses API, handled here
 *
 *   - **`reasoning.effort` has no `none`** for Muse Spark and the dial
 *     floors at `minimal`; `max` is Standard-tier 1.3 only (see
 *     {@see MuseSparkSurfaceTrait::modelSupportsMaxEffort()}).
 *   - **`reasoning.mode` / `reasoning.context`** are GPT-5.6 knobs the
 *     base class can emit; they are not part of Meta's surface and are
 *     stripped. `reasoning.summary` stays — Muse Spark streams reasoning
 *     *summaries* (`response.reasoning_summary_text.delta`); the raw chain
 *     of thought is never streamed on any route.
 *   - **`response_format` is not supported** — structured output goes
 *     through `text.format`, which the base already emits. A stray
 *     `response_format` (e.g. carried in `extra_body` from another
 *     provider) is stripped.
 *   - **`text.verbosity` is not supported** and is stripped.
 *   - **Unsupported OpenAI params** — `logprobs`, `top_logprobs`, `stop`,
 *     `logit_bias`, `prediction`, `modalities`, `audio`,
 *     `web_search_options`, `prompt_cache_options`, `service_tier`, and
 *     `n` > 1 — are stripped after `extra_body` merges.
 *   - **Search grounding** is the server-side `{"type": "web_search"}`
 *     tool (billed per query, on top of tokens), exposed as
 *     `options['grounding']`.
 *   - **`background: true`** runs the response asynchronously and cannot
 *     be combined with streaming. This provider always streams, so asking
 *     for it raises {@see FeatureNotSupportedException} rather than
 *     silently dropping the flag — the retrieve / cancel / delete
 *     endpoints it implies are not wired.
 */
class MetaResponsesProvider extends OpenAIResponsesProvider
{
    use MuseSparkSurfaceTrait;

    /**
     * Top-level params the Model API rejects on this route.
     */
    private const UNSUPPORTED_PARAMS = [
        'response_format',
        'logprobs',
        'top_logprobs',
        'stop',
        'logit_bias',
        'prediction',
        'modalities',
        'audio',
        'web_search_options',
        // GPT-5.6-only knobs the shared base can emit.
        'prompt_cache_options',
        'service_tier',
    ];

    protected function providerName(): string
    {
        return 'meta-responses';
    }

    protected function defaultModel(): string
    {
        return 'muse-spark-1.3';
    }

    protected function regionToBaseUrl(string $region): string
    {
        return 'https://api.meta.ai';
    }

    protected function chatCompletionsPath(): string
    {
        return 'v1/responses';
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function resolveBearer(array $config): ?string
    {
        return $this->resolveMetaBearer($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function missingBearerMessage(array $config): string
    {
        return $this->missingMetaBearerMessage();
    }

    /**
     * Muse Spark's dial, not OpenAI's: no `none`, and `max` only where the
     * model actually has it.
     */
    protected function normalizeEffortForModel(string $effort, string $model): string
    {
        if ($this->isMuseSpark($model)) {
            return $this->museSparkEffortTier($effort, $model) ?? strtolower(trim($effort));
        }

        return parent::normalizeEffortForModel($effort, $model);
    }

    protected function isMuseSpark(string $model): bool
    {
        return str_starts_with(strtolower($model), 'muse-');
    }

    /**
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
        if (! empty($options['background'])) {
            throw new FeatureNotSupportedException(
                feature: 'background',
                provider: 'meta-responses',
                model: (string) ($options['model'] ?? $this->model),
            );
        }

        $body = parent::buildRequestBody($messages, $tools, $systemPrompt, $options);

        // Search grounding — a server tool, merged alongside function tools.
        if (! empty($options['grounding']) || ! empty($options['web_search'])) {
            $body['tools'] = array_merge($body['tools'] ?? [], [['type' => 'web_search']]);
            $body['tool_choice'] ??= $options['tool_choice'] ?? 'auto';
        }

        // Stateless encrypted reasoning replay. Mutually exclusive with
        // server-side state, so it forces `store: false` and drops the
        // chaining id the base may have carried over from the last turn.
        if (! empty($options['reasoning_replay'])) {
            $include = $body['include'] ?? [];
            if (! in_array('reasoning.encrypted_content', $include, true)) {
                $include[] = 'reasoning.encrypted_content';
            }
            $body['include'] = array_values($include);
            $body['store'] = false;
        }

        if (! empty($body['include'])) {
            unset($body['previous_response_id']);
        }

        if (isset($options['safety_identifier']) && is_string($options['safety_identifier'])) {
            $body['safety_identifier'] = $options['safety_identifier'];
        }

        // `reasoning.mode` / `.context` are GPT-5.6 concepts; `summary` is
        // real here (Muse Spark streams reasoning summaries).
        if (isset($body['reasoning']) && is_array($body['reasoning'])) {
            unset($body['reasoning']['mode'], $body['reasoning']['context']);
            if ($body['reasoning'] === []) {
                unset($body['reasoning']);
            }
        }

        // `verbosity` is an OpenAI-only text control.
        if (isset($body['text']) && is_array($body['text'])) {
            unset($body['text']['verbosity']);
            if ($body['text'] === []) {
                unset($body['text']);
            }
        }

        foreach (self::UNSUPPORTED_PARAMS as $param) {
            unset($body[$param]);
        }

        if (isset($body['n']) && (int) $body['n'] !== 1) {
            unset($body['n']);
        }

        return $body;
    }
}
