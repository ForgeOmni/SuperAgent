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
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;
use SuperAgent\StreamingHandler;

/**
 * OpenAI Responses API (`/v1/responses`) — the wire protocol OpenAI
 * actively invests in (codex-rs ships ONLY this path as of late 2025;
 * they removed Chat Completions entirely per model-provider-info
 * CHAT_WIRE_API_REMOVED_ERROR).
 *
 * Distinct from {@see OpenAIProvider} (which stays on Chat Completions
 * for bc + OpenAI-compat downstream providers like Kimi/Qwen/GLM). Pick
 * this one when you want:
 *
 *   - `previous_response_id` — multi-turn that skips re-sending prior
 *     context; OpenAI holds the state server-side. Large cost savings
 *     on long sessions.
 *   - Native reasoning controls — `reasoning.effort = minimal|low|medium|
 *     high|xhigh` + `reasoning.summary` — no more hacking Chat
 *     Completions with model-id tricks for o1/o3/o4-mini.
 *   - `prompt_cache_key` — explicit server-side prompt cache pinning
 *     (same concept as Kimi's, now surfaced on the OpenAI wire).
 *   - `text.verbosity = low|medium|high` — response-length tuning.
 *   - `service_tier` — priority / default / flex / scale, affects
 *     rate-limit pool + price.
 *   - Classified failure events — `response.failed` carries an
 *     `error.code` that drops cleanly into
 *     {@see OpenAIErrorClassifier}.
 *
 * Selection is explicit: callers pass `provider: 'openai-responses'`
 * via ProviderRegistry, or set `wire_api: 'responses'` in a custom
 * provider config. Pre-existing callers that pass `provider: 'openai'`
 * keep hitting Chat Completions and see zero behaviour change.
 *
 * ## Input / output conversion
 *
 * The Responses API's `input` is a flat list of {@see ResponseItem}s.
 * SuperAgent's `Message[]` is structurally similar — each Message is
 * already typed (user / assistant / tool_result) with a content list.
 * {@see convertMessagesToInput()} does a near 1:1 translation, hoisting
 * the first system message into `instructions` per the API's contract.
 *
 * SSE event stream is different from Chat Completions: every event is
 * `event: <name>\ndata: <json>`, and we care about:
 *
 *   - `response.created`              — capture response_id for future
 *                                       previous_response_id continuation
 *   - `response.output_text.delta`    — streaming text
 *   - `response.output_item.done`     — a completed item (tool call,
 *                                       message, reasoning block)
 *   - `response.completed`            — final usage + stop
 *   - `response.failed` / `.incomplete` — error → classify + throw
 *
 * ## Not covered in this initial pass
 *
 *   - Image attachments in `input` (`input_image` content parts)
 *   - Streaming reasoning content on the Responses path (we accept the
 *     summary form via `reasoning.summary` but don't propagate
 *     per-token reasoning deltas into the UI yet — Chat Completions
 *     users don't have that surface either)
 *   - WebSocket transport (see the opt-in flag on OpenAIProvider;
 *     shipping as experimental)
 */
class OpenAIResponsesProvider extends OpenAIProvider
{
    /** Last known response_id — seeds `previous_response_id` on the next call. */
    protected ?string $lastResponseId = null;

    protected function providerName(): string
    {
        return 'openai-responses';
    }

    protected function defaultModel(): string
    {
        // gpt-5 is the current flagship on the Responses API path.
        // gpt-4o-family still works here but its native home is Chat
        // Completions; we default to the endpoint-native family so
        // callers who pick this provider get the intended shape.
        return 'gpt-5';
    }

    protected function chatCompletionsPath(): string
    {
        // The base class's retry loop calls this method for the
        // request path — we serve `/v1/responses` here while keeping
        // all of the retry / idle-timeout / env-header plumbing.
        //
        //   - ChatGPT OAuth backend exposes `/responses` (no v1).
        //   - Azure OpenAI exposes `/openai/responses?api-version=…`
        //     on a per-deployment base URL (deployment path lives in
        //     the `base_url`, not the request path).
        //   - Plain OpenAI is `/v1/responses`.
        if ($this->isAzure) {
            return 'openai/responses?api-version=' . rawurlencode((string) $this->azureApiVersion);
        }
        return $this->isChatGptOauth
            ? 'responses'
            : 'v1/responses';
    }

    /**
     * Captured from the constructor so `chatCompletionsPath()` and
     * future helpers can switch shape without re-parsing config.
     */
    protected bool $isChatGptOauth = false;

    /** Azure OpenAI deployment (base URL matches {@see self::isAzureBaseUrl()}). */
    protected bool $isAzure = false;

    /** `api-version` query string Azure requires on every request. */
    protected ?string $azureApiVersion = null;

    /** Detected from the first two matching markers in
     *  codex-rs's `is_azure_responses_provider` heuristic. */
    protected static function isAzureBaseUrl(string $baseUrl): bool
    {
        $lower = strtolower($baseUrl);
        foreach ([
            'openai.azure.',
            'cognitiveservices.azure.',
            'aoai.azure.',
            'azure-api.',
            'azurefd.',
            'windows.net/openai',
        ] as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }
        return false;
    }

    protected function regionToBaseUrl(string $region): string
    {
        // ChatGPT OAuth (Plus/Pro/Business) routes through a separate
        // backend that exposes the Responses API directly — base URL
        // `https://chatgpt.com/backend-api/codex`, paths are
        // `/responses` (no `/v1/` prefix), auth is the stored ChatGPT
        // access_token (which api.openai.com won't accept). Mirrors
        // codex-rs's behaviour in `ModelProviderInfo::to_api_provider`
        // when auth_mode is Chatgpt | ChatgptAuthTokens | AgentIdentity.
        //
        // The provider is constructed with a snapshot of the config,
        // so we only know the auth mode at construction time; the
        // flag is set there (see the constructor override below).
        if ($this->isChatGptOauth) {
            return 'https://chatgpt.com/backend-api/codex';
        }
        return 'https://api.openai.com';
    }

    protected function extraHeaders(array $config): array
    {
        $headers = parent::extraHeaders($config);
        // Azure deployments use `api-key: <key>` instead of a Bearer
        // Authorization header — the Bearer header is still sent but
        // Azure ignores it and consults api-key first. Adding both
        // keeps the request portable and matches how the official
        // Azure OpenAI SDK sends the key.
        if ($this->isAzure && isset($config['api_key']) && is_string($config['api_key'])) {
            $headers['api-key'] = $config['api_key'];
        }
        return $headers;
    }

    public function __construct(array $config)
    {
        // Experimental Responses-over-WebSocket transport.
        //
        // OpenAI's codex-rs ships this via a beta header
        // `openai-beta: responses_websockets=2026-02-06`, plus a
        // dedicated lazy WS connection pool per session and a v2
        // `response.create` prewarm step. It materially lowers
        // first-token latency on long conversations but depends on a
        // spec that's still explicitly beta at the time of this
        // commit — the header value is a dated snapshot, not a
        // stable contract, and the endpoint shape is expected to
        // shift before GA.
        //
        // Shipping the PHP client side would require:
        //   (a) a websocket client dependency (no standard stream
        //       equivalent in PHP core),
        //   (b) a prewarm path with ~200ms connect latency
        //       amortised across the session,
        //   (c) turn-state replay via `x-codex-turn-state` headers.
        //
        // For now we recognise the opt-in so callers' configs don't
        // throw on unknown keys, but any actual use of the WS path
        // raises a loud exception — there's no silent fallback,
        // because a silently-downgraded config would hide a real bug
        // when the caller expected the lower-latency transport.
        //
        // Remove this check and swap in a real transport when OpenAI
        // marks the WS endpoint GA and PHP has an acceptable ws
        // client story (or when we vendor one).
        if (! empty($config['experimental_ws_transport'])) {
            throw new \SuperAgent\Exceptions\FeatureNotSupportedException(
                feature: 'experimental_ws_transport',
                provider: 'openai-responses',
                model: $config['model'] ?? null,
            );
        }

        // Determine the auth mode BEFORE the parent runs the base URL
        // / header assembly. The parent calls `regionToBaseUrl()` in
        // its constructor, so `isChatGptOauth` has to be set first.
        $this->isChatGptOauth = ($config['auth_mode'] ?? null) === 'oauth'
            || (! isset($config['auth_mode']) && isset($config['access_token']));

        // Azure detection. Explicit `provider: 'azure'` keyword wins;
        // otherwise we pattern-match the base URL. Both paths require
        // an api-version query string — 2025-04-01-preview is the
        // current GA Responses-API version; callers with a different
        // deployment can override via `azure_api_version`.
        if (isset($config['base_url']) && is_string($config['base_url'])) {
            $this->isAzure = self::isAzureBaseUrl($config['base_url']);
        }
        if (($config['provider'] ?? null) === 'azure') {
            $this->isAzure = true;
        }
        if ($this->isAzure) {
            $this->azureApiVersion = (string) ($config['azure_api_version'] ?? '2025-04-01-preview');
        }

        parent::__construct($config);
    }

    /**
     * Expose the server-assigned response_id so callers that want to
     * pin a multi-turn conversation can stash it externally and feed
     * it back as `previous_response_id` on the next `chat()` call.
     *
     * Null when no completed response has been observed yet (fresh
     * provider instance, or the last call failed before
     * `response.created`).
     */
    public function lastResponseId(): ?string
    {
        return $this->lastResponseId;
    }

    // ── Request body ─────────────────────────────────────────────

    /**
     * Build the Responses API request body. Signature matches the base
     * class, but the body shape diverges from Chat Completions.
     *
     * @param  Message[]            $messages
     * @param  Tool[]               $tools
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function buildRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        // Hoist system → instructions. Any explicit $systemPrompt
        // wins; if absent we scan $messages for the first system
        // Message. Subsequent system messages (rare but legal under
        // our type) are folded into the input stream as-is.
        $instructions = $systemPrompt ?? '';

        $input = $this->convertMessagesToInput($messages);

        $body = [
            'model'                => $options['model'] ?? $this->model,
            'input'                => $input,
            'stream'               => true,
            // Opt out of server-side storage unless caller requested
            // it — `store: true` is required to use
            // `previous_response_id` afterward.
            'store'                => (bool) ($options['store'] ?? false),
            'parallel_tool_calls'  => (bool) ($options['parallel_tool_calls'] ?? true),
        ];

        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }

        if (! empty($tools)) {
            $body['tools'] = $this->convertToolsToResponses($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        // `max_output_tokens` replaces Chat Completions' `max_tokens`.
        if (isset($options['max_tokens']) || $this->maxTokens > 0) {
            $body['max_output_tokens'] = (int) ($options['max_tokens'] ?? $this->maxTokens);
        }

        // Reasoning. Accept two surfaces:
        //   1. Codex-style flat: options['reasoning'] = ['effort' => ..., 'summary' => ...]
        //   2. Feature-style:    options['features']['thinking']['budget_tokens']
        //      translated into effort buckets (same heuristic Kimi uses).
        $reasoning = $this->resolveReasoning($options);
        if ($reasoning !== null) {
            $body['reasoning'] = $reasoning;
        }

        // `text.verbosity` + `text.format` (JSON schema).
        $text = $this->resolveTextControls($options);
        if ($text !== null) {
            $body['text'] = $text;
        }

        // Prompt-cache pinning — shared contract with our Kimi
        // `prompt_cache_key` support. Accept both shapes for caller
        // convenience.
        $cacheKey = $options['prompt_cache_key']
            ?? $options['features']['prompt_cache_key']['session_id']
            ?? null;
        if (is_string($cacheKey) && $cacheKey !== '') {
            $body['prompt_cache_key'] = $cacheKey;
        }

        if (isset($options['service_tier']) && is_string($options['service_tier'])) {
            $body['service_tier'] = $options['service_tier'];
        }

        // Multi-turn continuation — caller feeds back the response_id
        // from a previous turn (or we remember it internally).
        $previousId = $options['previous_response_id'] ?? $this->lastResponseId;
        if (is_string($previousId) && $previousId !== '') {
            $body['previous_response_id'] = $previousId;
        }

        if (! empty($options['include']) && is_array($options['include'])) {
            $body['include'] = array_values(array_filter(
                array_map('strval', $options['include']),
                static fn($s): bool => $s !== '',
            ));
        }

        // W3C trace-context injection. Accepts any of:
        //   - options['trace_context'] as a TraceContext instance
        //   - options['traceparent'] (+ optional options['tracestate'])
        //     as raw strings — matches the HTTP header spelling
        // Either source is folded into client_metadata so OpenAI's
        // provider-side logs can be correlated with the host's trace.
        $metadata = [];
        if (! empty($options['client_metadata']) && is_array($options['client_metadata'])) {
            $metadata = $options['client_metadata'];
        }
        $traceContext = null;
        if (isset($options['trace_context']) && $options['trace_context'] instanceof \SuperAgent\Support\TraceContext) {
            $traceContext = $options['trace_context'];
        } elseif (isset($options['traceparent']) && is_string($options['traceparent'])) {
            $traceContext = \SuperAgent\Support\TraceContext::parse($options['traceparent']);
            if ($traceContext !== null && isset($options['tracestate']) && is_string($options['tracestate'])) {
                $traceContext = new \SuperAgent\Support\TraceContext(
                    $traceContext->traceparent,
                    $options['tracestate']
                );
            }
        }
        if ($traceContext !== null) {
            $metadata = array_merge($metadata, $traceContext->asClientMetadata());
        }
        if ($metadata !== []) {
            $body['client_metadata'] = $metadata;
        }

        // Power-user escape hatch — same as the Chat Completions
        // pathway. Deep-merged last so callers can inject anything we
        // haven't shipped a first-class knob for.
        if (isset($options['extra_body']) && is_array($options['extra_body'])) {
            $this->mergeExtraBody($body, $options['extra_body']);
        }

        return $body;
    }

    /**
     * Convert SuperAgent's Message[] into the Responses `input` array.
     * Encoding lives in the shared `OpenAIResponsesEncoder` via the
     * Transcoder facade. Kept here as a protected hook so any
     * subclass that needs a Responses-specific override still has a
     * stable extension point.
     *
     * @param  Message[] $messages
     * @return list<array<string,mixed>>
     */
    protected function convertMessagesToInput(array $messages): array
    {
        return (new \SuperAgent\Conversation\Transcoder())
            ->encode($messages, \SuperAgent\Conversation\WireFamily::OpenAIResponses);
    }

    /**
     * Convert SuperAgent Tools to the Responses-API tool shape.
     * Different from Chat Completions: no outer `{type:"function",
     * function: {...}}` wrapper — the Responses API wants the
     * function fields at the top level of each tool entry.
     *
     * @param  Tool[] $tools
     * @return list<array<string,mixed>>
     */
    protected function convertToolsToResponses(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof \SuperAgent\Tools\Tool) continue;
            $out[] = [
                'type'        => 'function',
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'parameters'  => $tool->inputSchema(),
                'strict'      => false,
            ];
        }
        return $out;
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    protected function resolveReasoning(array $options): ?array
    {
        if (isset($options['reasoning']) && is_array($options['reasoning'])) {
            return $options['reasoning'];
        }

        $thinking = $options['features']['thinking'] ?? null;
        if (is_array($thinking)) {
            $budget = (int) ($thinking['budget_tokens'] ?? 0);
            if ($budget > 0) {
                return [
                    'effort'  => $this->budgetToEffort($budget),
                    'summary' => $thinking['summary'] ?? 'auto',
                ];
            }
            if (isset($thinking['effort']) && is_string($thinking['effort'])) {
                return [
                    'effort'  => $thinking['effort'],
                    'summary' => $thinking['summary'] ?? 'auto',
                ];
            }
        }
        return null;
    }

    protected function budgetToEffort(int $budget): string
    {
        // Same thresholds we use for Kimi.
        if ($budget < 2000) return 'low';
        if ($budget <= 8000) return 'medium';
        return 'high';
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    protected function resolveTextControls(array $options): ?array
    {
        $text = [];
        if (isset($options['verbosity']) && is_string($options['verbosity'])) {
            $text['verbosity'] = $options['verbosity'];
        }
        if (isset($options['response_format']) && is_array($options['response_format'])) {
            // Map Chat-Completions `response_format: {type:json_schema,
            // json_schema:{schema,name,strict}}` onto Responses
            // `text.format: {type:json_schema,schema,name,strict}`.
            $rf = $options['response_format'];
            if (($rf['type'] ?? null) === 'json_schema') {
                $js = $rf['json_schema'] ?? [];
                $text['format'] = [
                    'type'   => 'json_schema',
                    'name'   => (string) ($js['name'] ?? 'Output'),
                    'strict' => (bool) ($js['strict'] ?? true),
                    'schema' => $js['schema'] ?? [],
                ];
            }
        }
        return $text === [] ? null : $text;
    }

    // ── SSE parsing — event-typed stream ─────────────────────────

    public function chat(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): Generator {
        $body = $this->buildRequestBody($messages, $tools, $systemPrompt, $options);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->client->post($this->chatCompletionsPath(), [
                    'json'   => $body,
                    'stream' => true,
                    'curl'   => [
                        CURLOPT_LOW_SPEED_LIMIT => 1,
                        CURLOPT_LOW_SPEED_TIME  => (int) max(1, (int) ceil($this->streamIdleTimeoutMs / 1000)),
                    ],
                ]);
                break;
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                if (($status === 429 || $status >= 500) && $attempt < $this->requestMaxRetries) {
                    $attempt++;
                    usleep((int) ($this->getRetryDelay($attempt, $e->getResponse()) * 1_000_000));
                    continue;
                }

                throw OpenAIErrorClassifier::classify(
                    statusCode: $status,
                    body: is_array($responseBody) ? $responseBody : null,
                    message: $responseBody['error']['message'] ?? $e->getMessage(),
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

        yield from $this->parseResponsesSseStream(
            $response->getBody(),
            $options['streaming_handler'] ?? null,
        );
    }

    /**
     * Parse the event-typed Responses SSE stream. Unlike Chat
     * Completions (where every line is `data: <json>` and a single
     * `finish_reason` terminates the turn), the Responses API uses
     * `event: <name>\ndata: <json>` pairs with a typed event per
     * line of interest.
     *
     * Event vocabulary covered here (from codex-rs
     * `codex-api/src/sse/responses.rs`):
     *
     *   - response.created               — capture response.id
     *   - response.output_text.delta     — text streaming
     *   - response.output_item.done      — finished item (message /
     *                                      function_call / reasoning)
     *   - response.completed             — final usage + end of turn
     *   - response.failed                — classified error
     *   - response.incomplete            — partial termination
     *
     * @param \Psr\Http\Message\StreamInterface $stream
     */
    protected function parseResponsesSseStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $buffer = '';
        $eventType = '';
        $dataLines = [];

        $messageContent = '';
        /** @var array<int, array{id:string,name:string,arguments:string}> */
        $toolCalls = [];
        $usage = null;
        $stopReason = null;

        $dispatched = false;

        $dispatch = function () use (
            &$eventType, &$dataLines,
            &$messageContent, &$toolCalls, &$usage, &$stopReason,
            &$dispatched, $handler
        ): array {
            if ($eventType === '' || $dataLines === []) {
                return [true, null];
            }
            $dataJson = implode("\n", $dataLines);
            $data = json_decode($dataJson, true);
            $eventType_local = $eventType;
            $eventType = '';
            $dataLines = [];
            if (! is_array($data)) return [true, null];

            [$continue, $_result] = $this->handleResponsesEvent(
                $eventType_local,
                $data,
                $messageContent,
                $toolCalls,
                $usage,
                $stopReason,
                $handler,
            );
            if (! $continue) $dispatched = true; // signal terminal
            return [$continue, null];
        };

        $done = false;
        while (! $done && ! $stream->eof()) {
            $chunk = $stream->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    [$continue, $_] = $dispatch();
                    if (! $continue) { $done = true; break; }
                    continue;
                }

                if (str_starts_with($line, 'event: ')) {
                    $eventType = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $dataLines[] = substr($line, 6);
                } elseif (str_starts_with($line, ':')) {
                    // SSE comment — ignore.
                }
            }
        }

        // Tail dispatch — streams that close without a terminating
        // blank line still have the last event in the accumulators.
        // OpenAI's SSE is well-behaved here, but local test fixtures
        // and badly-terminating proxies hit this path.
        if (! $done && $eventType !== '' && $dataLines !== []) {
            $dispatch();
        }

        // Assemble the final assistant message.
        $content = [];
        if ($messageContent !== '') {
            $content[] = ContentBlock::text($messageContent);
        }
        foreach ($toolCalls as $tc) {
            $block = ContentBlock::toolUse(
                $tc['id'],
                $tc['name'],
                self::decodeToolArguments($tc['arguments']),
            );
            $content[] = $block;
            $handler?->emitToolUse($block);
        }

        $msg = new AssistantMessage();
        $msg->content = $content;
        $msg->usage = $usage;
        $msg->stopReason = $stopReason ?? StopReason::EndTurn;
        yield $msg;
    }

    /**
     * Dispatch a single parsed SSE event. Mutates the accumulators
     * by reference; returns `[continueLooping, result]`.
     *
     * @param array<string, mixed> $data
     * @param array<int, array{id:string,name:string,arguments:string}> $toolCalls
     */
    protected function handleResponsesEvent(
        string $eventType,
        array $data,
        string &$messageContent,
        array &$toolCalls,
        ?Usage &$usage,
        ?StopReason &$stopReason,
        ?StreamingHandler $handler,
    ): array {
        switch ($eventType) {
            case 'response.created':
                if (isset($data['response']['id']) && is_string($data['response']['id'])) {
                    $this->lastResponseId = $data['response']['id'];
                }
                return [true, null];

            case 'response.output_text.delta':
                $delta = (string) ($data['delta'] ?? '');
                if ($delta !== '') {
                    $messageContent .= $delta;
                    $handler?->emitText($delta, $messageContent);
                }
                return [true, null];

            case 'response.output_item.done':
                $item = $data['item'] ?? null;
                if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                    $idx = count($toolCalls);
                    $toolCalls[$idx] = [
                        'id'        => (string) ($item['call_id'] ?? ''),
                        'name'      => (string) ($item['name'] ?? ''),
                        'arguments' => (string) ($item['arguments'] ?? ''),
                    ];
                }
                return [true, null];

            case 'response.completed':
                $resp = $data['response'] ?? null;
                if (is_array($resp)) {
                    if (isset($resp['id']) && is_string($resp['id'])) {
                        $this->lastResponseId = $resp['id'];
                    }
                    if (isset($resp['usage']) && is_array($resp['usage'])) {
                        $u = $resp['usage'];
                        $usage = new Usage(
                            inputTokens: (int) ($u['input_tokens'] ?? $u['prompt_tokens'] ?? 0),
                            outputTokens: (int) ($u['output_tokens'] ?? $u['completion_tokens'] ?? 0),
                            cacheCreationInputTokens: isset($u['cache_creation_input_tokens'])
                                ? (int) $u['cache_creation_input_tokens']
                                : null,
                            cacheReadInputTokens: isset($u['input_tokens_details']['cached_tokens'])
                                ? (int) $u['input_tokens_details']['cached_tokens']
                                : (isset($u['cached_tokens']) ? (int) $u['cached_tokens'] : null),
                        );
                    }
                }
                $stopReason = count($toolCalls) > 0 ? StopReason::ToolUse : StopReason::EndTurn;
                return [false, null]; // terminal

            case 'response.failed':
            case 'response.incomplete':
                $resp = $data['response'] ?? [];
                $error = is_array($resp) ? ($resp['error'] ?? null) : null;
                $status = $eventType === 'response.incomplete' ? 200 : 500;
                $body = is_array($error) ? ['error' => $error] : null;
                throw OpenAIErrorClassifier::classify(
                    statusCode: $status,
                    body: $body,
                    message: is_array($error)
                        ? (string) ($error['message'] ?? $eventType)
                        : $eventType,
                    provider: $this->providerName(),
                );
        }
        return [true, null];
    }
}
