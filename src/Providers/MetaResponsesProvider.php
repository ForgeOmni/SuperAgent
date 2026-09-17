<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use Generator;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use SuperAgent\Enums\StopReason;
use SuperAgent\Exceptions\Provider\OpenAIErrorClassifier;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Usage;
use SuperAgent\Providers\Capabilities\SupportsBackgroundResponses;
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
 *   - **`background: true`** detaches the turn from the connection and
 *     cannot be combined with streaming. It therefore has its own entry
 *     point — {@see submitBackground()} and the rest of
 *     {@see SupportsBackgroundResponses} — rather than an option on
 *     `chat()`, which always streams. Passing it to `chat()` raises, with
 *     a message pointing at the right method.
 *
 * ## Background lifecycle
 *
 * ```php
 * $job = $provider->submitBackground($messages, $tools, $system, [
 *     'reasoning_effort' => 'max',
 * ]);
 *
 * while (! $provider->poll($job)->isTerminal()) {
 *     sleep(2);
 * }
 *
 * $message = $provider->fetch($job);     // AssistantMessage
 * $provider->deleteBackground($job);     // stored objects are the caller's to clean up
 * ```
 *
 * Or re-attach and render it like a live turn:
 * `foreach ($provider->followBackground($job) as $message) { … }`.
 */
class MetaResponsesProvider extends OpenAIResponsesProvider implements SupportsBackgroundResponses
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
        // `background` is a different lifecycle, not a request flag: Meta
        // rejects it alongside `stream: true`, and chat() always streams.
        // Route the caller to the method that actually implements it
        // rather than dropping the flag and streaming anyway.
        if (! empty($options['background']) && empty($options['__background_submit'])) {
            throw new ProviderException(
                'background responses cannot be combined with streaming — use '
                . 'submitBackground() / poll() / fetch() (SupportsBackgroundResponses) instead',
                'meta-responses',
            );
        }
        unset($options['__background_submit']);

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

    // ── Background lifecycle (SupportsBackgroundResponses) ───────

    /**
     * Submit a turn that outlives the HTTP request.
     *
     * Forces what Meta requires for a detached run regardless of what the
     * caller passed: `background: true`, `stream: false` (the pair is a
     * 400), and `store: true` — without storage a background response is
     * dropped after roughly ten minutes, which would make `poll()` race
     * the garbage collector. That also rules out encrypted reasoning
     * replay, which is the stateless mode; a background job is by
     * definition server-side state.
     *
     * @param  array<int, mixed>    $messages
     * @param  array<int, mixed>    $tools
     * @param  array<string, mixed> $options
     */
    public function submitBackground(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): JobHandle {
        $options['__background_submit'] = true;
        $body = $this->buildRequestBody($messages, $tools, $systemPrompt, $options);

        $body['background'] = true;
        $body['stream'] = false;
        $body['store'] = true;
        unset($body['stream_options'], $body['include']);

        $decoded = $this->requestJson('POST', $this->chatCompletionsPath(), $body);

        $id = $decoded['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new ProviderException(
                'Meta background submit returned no response id',
                $this->providerName(),
            );
        }

        $this->lastResponseId = $id;

        return JobHandle::new(
            provider: $this->providerName(),
            jobId: $id,
            kind: 'response',
            meta: [
                'model'  => (string) ($decoded['model'] ?? $body['model']),
                'status' => (string) ($decoded['status'] ?? 'queued'),
            ],
        );
    }

    public function poll(JobHandle $handle): JobStatus
    {
        $decoded = $this->requestJson('GET', $this->responsePath($handle));

        return self::mapResponseStatus((string) ($decoded['status'] ?? ''));
    }

    /**
     * The finished turn as an {@see AssistantMessage} — same shape `chat()`
     * yields, so a background result renders through the same code path.
     *
     * Throws if the job failed; a job that stopped early (`incomplete`,
     * e.g. it hit `max_output_tokens`) returns what it produced with
     * `StopReason::MaxTokens`, because truncated output is still output.
     */
    public function fetch(JobHandle $handle): mixed
    {
        $decoded = $this->requestJson('GET', $this->responsePath($handle));
        $status = (string) ($decoded['status'] ?? '');

        if ($status === 'failed') {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
            throw OpenAIErrorClassifier::classify(
                statusCode: 500,
                body: $error !== null ? ['error' => $error] : null,
                message: (string) ($error['message'] ?? 'background response failed'),
                provider: $this->providerName(),
            );
        }

        return $this->responseToMessage($decoded);
    }

    /**
     * Best-effort cancel. Meta returns the response\'s current state, and a
     * cancel that lands in the same instant the turn finishes comes back
     * `completed` rather than as an error — either way the caller should
     * stop polling, so both count as acknowledged.
     */
    public function cancel(JobHandle $handle): bool
    {
        try {
            $decoded = $this->requestJson('POST', $this->responsePath($handle) . '/cancel');
        } catch (\Throwable) {
            return false;
        }

        return self::mapResponseStatus((string) ($decoded['status'] ?? ''))->isTerminal();
    }

    /**
     * Soft-delete the stored response. Returns false when it was already
     * gone (Meta answers 404 — the endpoint is not idempotent).
     */
    public function deleteBackground(JobHandle $handle): bool
    {
        try {
            $this->requestJson('DELETE', $this->responsePath($handle));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Re-attach to a submitted job and stream the rest of it.
     *
     * This is the stored-response stream (`?stream=true`), not the live
     * one: Meta emits `response.created`, then the terminal event carrying
     * the whole final response, then `[DONE]`. No token deltas — they were
     * generated while nobody was listening. The base SSE parser handles
     * exactly those events, so the yielded message is identical in shape
     * to a live turn.
     *
     * @return Generator<int, \SuperAgent\Messages\Message>
     */
    public function followBackground(JobHandle $handle, ?int $startingAfter = null): Generator
    {
        $path = $this->responsePath($handle) . '?stream=true';
        if ($startingAfter !== null) {
            $path .= '&starting_after=' . $startingAfter;
        }

        $response = $this->send('GET', $path, null, stream: true);

        yield from $this->parseResponsesSseStream($response->getBody(), null);
    }

    /**
     * Ask the server how many input tokens a conversation would cost
     * before spending a turn on it — the check to run when compaction has
     * trimmed history and you need to know it now fits.
     *
     * @param  array<int, mixed>    $messages
     * @param  array<int, mixed>    $tools
     * @param  array<string, mixed> $options
     */
    public function countInputTokens(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): int {
        $body = $this->buildRequestBody($messages, $tools, $systemPrompt, $options);
        unset($body['stream'], $body['stream_options'], $body['store'], $body['include']);

        $decoded = $this->requestJson('POST', 'v1/responses/input_tokens', $body);

        return (int) ($decoded['input_tokens'] ?? $decoded['total_tokens'] ?? 0);
    }

    // ── Background plumbing ──────────────────────────────────────

    protected function responsePath(JobHandle $handle): string
    {
        return 'v1/responses/' . rawurlencode($handle->jobId);
    }

    /**
     * Meta\'s response `status` → the SDK\'s job lifecycle.
     *
     * `incomplete` is terminal-with-output (the turn stopped early), so it
     * maps to Done and `fetch()` returns the truncated content rather than
     * throwing it away.
     */
    protected static function mapResponseStatus(string $status): JobStatus
    {
        return match (strtolower(trim($status))) {
            'queued'                 => JobStatus::Pending,
            'in_progress', 'running' => JobStatus::Running,
            'completed', 'incomplete' => JobStatus::Done,
            'failed', 'error'        => JobStatus::Failed,
            'cancelled', 'canceled'  => JobStatus::Canceled,
            default                  => JobStatus::Running,
        };
    }

    /**
     * Convert a stored response object into an assistant message — the
     * non-streaming counterpart of the SSE assembler in the base class.
     *
     * @param array<string, mixed> $response
     */
    protected function responseToMessage(array $response): AssistantMessage
    {
        $text = '';
        $content = [];
        $toolCalls = [];

        foreach (($response['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? '') === 'message') {
                foreach (($item['content'] ?? []) as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'output_text') {
                        $text .= (string) ($part['text'] ?? '');
                    }
                }
                continue;
            }

            if (($item['type'] ?? '') === 'function_call') {
                $toolCalls[] = ContentBlock::toolUse(
                    (string) ($item['call_id'] ?? ''),
                    (string) ($item['name'] ?? ''),
                    self::decodeToolArguments((string) ($item['arguments'] ?? '')),
                );
            }
        }

        if ($text !== '') {
            $content[] = ContentBlock::text($text);
        }
        foreach ($toolCalls as $block) {
            $content[] = $block;
        }

        $message = new AssistantMessage();
        $message->content = $content;
        $message->usage = self::usageFromResponse($response['usage'] ?? null);
        $message->stopReason = match (true) {
            $toolCalls !== []                              => StopReason::ToolUse,
            ($response['status'] ?? '') === 'incomplete'   => StopReason::MaxTokens,
            default                                        => StopReason::EndTurn,
        };

        return $message;
    }

    /**
     * @param mixed $usage
     */
    protected static function usageFromResponse($usage): ?Usage
    {
        if (! is_array($usage)) {
            return null;
        }

        return new Usage(
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheCreationInputTokens: null,
            cacheReadInputTokens: isset($usage['input_tokens_details']['cached_tokens'])
                ? (int) $usage['input_tokens_details']['cached_tokens']
                : null,
        );
    }

    /**
     * One JSON request with the same retry policy `chat()` uses.
     *
     * @param  array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $path, ?array $body = null): array
    {
        $response = $this->send($method, $path, $body);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Shared transport for the non-streaming lifecycle endpoints: retries
     * 429 / 5xx like `chat()` does and classifies failures through the
     * same error classifier, so a background job reports problems the way
     * a live turn does.
     *
     * @param array<string, mixed>|null $body
     */
    protected function send(
        string $method,
        string $path,
        ?array $body = null,
        bool $stream = false,
    ): \Psr\Http\Message\ResponseInterface {
        $options = [];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($stream) {
            $options['stream'] = true;
        }

        $attempt = 0;
        while (true) {
            try {
                return $this->client->request($method, $path, $options);
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $decoded = json_decode((string) $e->getResponse()->getBody(), true);

                if (($status === 429 || $status >= 500) && $attempt < $this->requestMaxRetries) {
                    $attempt++;
                    usleep((int) ($this->getRetryDelay($attempt, $e->getResponse()) * 1_000_000));
                    continue;
                }

                throw OpenAIErrorClassifier::classify(
                    statusCode: $status,
                    body: is_array($decoded) ? $decoded : null,
                    message: $decoded['error']['message'] ?? $e->getMessage(),
                    provider: $this->providerName(),
                    previous: $e,
                );
            } catch (GuzzleException $e) {
                if ($attempt < $this->requestMaxRetries) {
                    $attempt++;
                    usleep((int) ($this->jitteredBackoff($attempt) * 1_000_000));
                    continue;
                }

                throw new ProviderException($e->getMessage(), $this->providerName(), previous: $e);
            }
        }
    }
}
