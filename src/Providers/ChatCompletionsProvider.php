<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Exceptions\Provider\OpenAIErrorClassifier;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;
use SuperAgent\Providers\Features\FeatureDispatcher;
use SuperAgent\StreamingHandler;
use SuperAgent\Tools\Tool;

/**
 * Abstract base for every LLM provider that speaks the
 * `/chat/completions` wire protocol — the request/response shape OpenAI
 * introduced and that Kimi, GLM and MiniMax (and many others) have since
 * adopted. It is NOT "OpenAI-specific": the concrete `OpenAIProvider`
 * inherits from this class just like the others do.
 *
 * What lives here:
 *   - Bearer-authenticated Guzzle client setup
 *   - Region-aware base URL resolution (default / intl / cn / …)
 *   - Request body assembly: messages, tools, temperature, max_tokens, response_format
 *   - SSE streaming parser
 *   - Retry loop on 429 / 5xx
 *   - Message / tool format conversion
 *
 * What subclasses override:
 *   - `providerName()` — the id used in errors, registry keys, logs
 *   - `defaultRegion()` / `regionToBaseUrl()` — per-vendor region map
 *   - `defaultModel()` — vendor-specific default
 *   - `resolveBearer()` / `missingBearerMessage()` — when the bearer is
 *     somewhere other than `$config['api_key']` (OpenAI OAuth path)
 *   - `chatCompletionsPath()` — some vendors prefix their own path
 *     (GLM base URL already contains `/api/paas/v4/`)
 *   - `extraHeaders()` — OpenAI's `OpenAI-Organization`, MiniMax's `X-GroupId`
 *   - `customizeRequestBody()` — GLM's `thinking` field, future native knobs
 *
 * Providers whose wire format is NOT chat-completions (Anthropic Messages,
 * Gemini `streamGenerateContent`, DashScope `text-generation/generation`,
 * Bedrock, Ollama) implement `LLMProvider` directly and stay independent
 * of this base — there is no polymorphism or shared plumbing with them.
 */
abstract class ChatCompletionsProvider implements LLMProvider
{
    protected Client $client;
    protected string $model;
    protected int $maxTokens;
    protected int $maxRetries;

    /** Retries for the initial HTTP connect + response status. */
    protected int $requestMaxRetries;

    /** Stream-scoped retries (used by Responses providers that can resume). */
    protected int $streamMaxRetries;

    /** SSE idle cutoff — cURL kills the connection after this many ms without data. */
    protected int $streamIdleTimeoutMs;
    protected string $region;
    protected string $apiKey;

    public function __construct(array $config)
    {
        $bearer = $this->resolveBearer($config);
        if (empty($bearer)) {
            throw new ProviderException(
                $this->missingBearerMessage($config),
                $this->providerName(),
            );
        }
        $this->apiKey = $bearer;

        $this->region = $config['region'] ?? $this->defaultRegion();
        $this->model = $config['model'] ?? $this->defaultModel();
        $this->maxTokens = (int) ($config['max_tokens'] ?? 4096);
        // Layered retry policy, modelled on codex-rs (model-provider-info
        // defaults: request_max_retries=4, stream_max_retries=5,
        // stream_idle_timeout_ms=300_000).
        //
        //   max_retries           — legacy single knob, used as fallback
        //                           for both counters when set.
        //   request_max_retries   — retries for the initial HTTP connect /
        //                           response code (429 / 5xx / network).
        //   stream_max_retries    — reserved; taken up by providers that
        //                           know how to resume mid-stream (Responses
        //                           API via `previous_response_id`).
        //   stream_idle_timeout_ms — low-speed cutoff on the SSE connection.
        //                           Translated to cURL LOW_SPEED_LIMIT/TIME
        //                           on the request so an idle server doesn't
        //                           hang the agent.
        $this->maxRetries        = (int) ($config['max_retries'] ?? 3);
        $this->requestMaxRetries = isset($config['request_max_retries'])
            ? (int) $config['request_max_retries']
            : $this->maxRetries;
        $this->streamMaxRetries  = isset($config['stream_max_retries'])
            ? (int) $config['stream_max_retries']
            : max(5, $this->maxRetries);
        $this->streamIdleTimeoutMs = (int) ($config['stream_idle_timeout_ms'] ?? 300_000);
        // Guard against silly configs. Codex caps at 100; we follow.
        $this->requestMaxRetries  = max(0, min($this->requestMaxRetries, 100));
        $this->streamMaxRetries   = max(0, min($this->streamMaxRetries, 100));
        $this->streamIdleTimeoutMs = max(1_000, min($this->streamIdleTimeoutMs, 3_600_000));

        $baseUrl = rtrim(
            $config['base_url'] ?? $this->regionToBaseUrl($this->region),
            '/',
        ) . '/';

        $headers = [
            'Authorization' => 'Bearer ' . $bearer,
            'Content-Type' => 'application/json',
        ];
        foreach ($this->extraHeaders($config) as $name => $value) {
            $headers[$name] = $value;
        }

        // Declarative env-var → HTTP header mapping. When config contains
        //
        //   env_http_headers => ['OpenAI-Organization' => 'OPENAI_ORGANIZATION',
        //                        'OpenAI-Project'      => 'OPENAI_PROJECT']
        //
        // each header is included only when its env var is set + non-empty.
        // Mirrors codex's `env_http_headers` config so downstream consumers
        // can add vendor-specific headers (OpenAI project scoping, Fireworks
        // deployment id, etc.) without a code change per header. Lifted to
        // the base class so every OpenAI-compat provider benefits.
        if (! empty($config['env_http_headers']) && is_array($config['env_http_headers'])) {
            foreach ($config['env_http_headers'] as $headerName => $envVar) {
                if (! is_string($headerName) || ! is_string($envVar) || $envVar === '') {
                    continue;
                }
                $val = $_ENV[$envVar] ?? getenv($envVar);
                if ($val === false || $val === '' || ! is_string($val)) {
                    continue;
                }
                $trimmed = trim($val);
                if ($trimmed === '') continue;
                $headers[$headerName] = $trimmed;
            }
        }

        // Plain static headers: `http_headers => ['version' => '0.9.0']`.
        // Complements `env_http_headers` for values that don't depend on
        // the environment. Later keys win over earlier ones; env_http
        // wins over http_headers wins over extraHeaders() wins over the
        // hard-coded Authorization/Content-Type.
        if (! empty($config['http_headers']) && is_array($config['http_headers'])) {
            foreach ($config['http_headers'] as $headerName => $value) {
                if (is_string($headerName) && is_scalar($value)) {
                    $headers[$headerName] = (string) $value;
                }
            }
        }

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => $headers,
            'timeout' => (int) ($config['timeout'] ?? 300),
        ]);
    }

    // ── Subclass contract ────────────────────────────────────────

    /** Provider id used in errors, logs, registry keys. */
    abstract protected function providerName(): string;

    /** Default region when the caller didn't specify one. */
    abstract protected function defaultRegion(): string;

    /** Map region → base URL (without trailing slash is fine, constructor adds one). */
    abstract protected function regionToBaseUrl(string $region): string;

    /** Default model id for this vendor. */
    abstract protected function defaultModel(): string;

    /**
     * Extract the bearer token from config. Default: `$config['api_key']`.
     * OpenAI overrides to also accept `$config['access_token']` under OAuth.
     *
     * @param array<string, mixed> $config
     */
    protected function resolveBearer(array $config): ?string
    {
        return $config['api_key'] ?? null;
    }

    /**
     * Message shown when `resolveBearer()` returns null/empty. Subclasses can
     * tailor it ("OAuth access_token is required" when the caller asked for
     * OAuth mode but didn't provide a token).
     *
     * @param array<string, mixed> $config
     */
    protected function missingBearerMessage(array $config): string
    {
        return 'API key is required';
    }

    /**
     * Request path appended to the base URL for chat completions. Most
     * vendors use `v1/chat/completions`; GLM uses `chat/completions` because
     * the base URL already contains `/api/paas/v4/`; MiniMax uses
     * `v1/text/chatcompletion_v2`.
     */
    protected function chatCompletionsPath(): string
    {
        return 'v1/chat/completions';
    }

    /**
     * Extra HTTP headers beyond `Authorization` / `Content-Type` — e.g.
     * OpenAI `OpenAI-Organization`, MiniMax `X-GroupId`.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    protected function extraHeaders(array $config): array
    {
        return [];
    }

    /**
     * Hook for subclasses to inject vendor-specific body fields after the
     * shared shape has been built — GLM's `thinking`, future native knobs.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     */
    protected function customizeRequestBody(array &$body, array $options): void
    {
        // no-op
    }

    // ── LLMProvider contract ─────────────────────────────────────

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
                    'json' => $body,
                    'stream' => true,
                    // cURL idle-timeout: if throughput drops below 1 byte for
                    // the configured window, the connection is killed and
                    // we raise via the GuzzleException catch below. Lifted
                    // from codex's `stream_idle_timeout_ms` (default 300s).
                    'curl' => [
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
                    $delay = $this->getRetryDelay($attempt, $e->getResponse());
                    usleep((int) ($delay * 1_000_000));
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

        yield from $this->parseSSEStream(
            $response->getBody(),
            $options['streaming_handler'] ?? null,
        );
    }

    public function formatMessages(array $messages): array
    {
        // Fast path: the encoder is stateless and processes the whole
        // list in one call, so we skip the per-message convertMessage()
        // hop. Subclasses that override convertMessage() will still see
        // their override honored via buildRequestBody() below.
        return (new \SuperAgent\Conversation\Transcoder())
            ->encode($messages, \SuperAgent\Conversation\WireFamily::OpenAIChat);
    }

    public function formatTools(array $tools): array
    {
        return $this->convertTools($tools);
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function name(): string
    {
        return $this->providerName();
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    // ── Request / response plumbing ──────────────────────────────

    protected function buildRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        $out = [];

        if ($systemPrompt) {
            $out[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Body assembly funnels through the same Transcoder as
        // formatMessages() so the wire shape stays consistent regardless
        // of which entry point a caller uses. Subclasses that need a
        // vendor-specific wire override formatMessages() at the public
        // API level rather than poking at convertMessage().
        foreach ($this->formatMessages($messages) as $wire) {
            $out[] = $wire;
        }

        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $out,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->maxTokens),
            'stream' => true,
            'temperature' => (float) ($options['temperature'] ?? 0.7),
        ];

        if (! empty($tools)) {
            $body['tools'] = $this->convertTools($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        $this->customizeRequestBody($body, $options);

        // Generic feature dispatch (thinking / caching / web_search / …).
        // No-op when `$options['features']` is absent — Compat-safe.
        FeatureDispatcher::apply($this, $options, $body);

        // Power-user escape hatch: $options['extra_body'] is deep-merged
        // at the top level of the request body, AFTER customizeRequestBody
        // and FeatureDispatcher so it always wins. Mirrors OpenAI SDK's
        // `extra_body` convention — lets callers pass vendor-specific
        // fields (Kimi `prompt_cache_key`, Qwen-proxied fields, GLM extras)
        // without waiting for us to ship a feature adapter. No-op when the
        // key is absent, so this stays Compat-safe for every pre-existing
        // caller.
        if (isset($options['extra_body']) && is_array($options['extra_body'])) {
            $this->mergeExtraBody($body, $options['extra_body']);
        }

        return $body;
    }

    /**
     * Deep-merge a user-provided $options['extra_body'] fragment into the
     * outgoing request body. Semantics match FeatureAdapter::merge():
     *
     *   - Scalar + scalar       → overwrite.
     *   - Assoc array + assoc   → recursive merge (leaf-wins).
     *   - Indexed list + list   → replace wholesale (we treat arrays like
     *     `messages` or `tools` as single units; adapters or callers that
     *     want to append should handle that themselves).
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $extraBody
     */
    protected function mergeExtraBody(array &$body, array $extraBody): void
    {
        foreach ($extraBody as $k => $v) {
            if (is_array($v) && isset($body[$k]) && is_array($body[$k])
                && self::isAssocArray($v) && self::isAssocArray($body[$k])) {
                $sub = $body[$k];
                $this->mergeExtraBody($sub, $v);
                $body[$k] = $sub;
                continue;
            }
            $body[$k] = $v;
        }
    }

    /**
     * @param array<int|string, mixed> $a
     */
    private static function isAssocArray(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        return array_keys($a) !== range(0, count($a) - 1);
    }

    /**
     * Convert one internal Message into ZERO OR MORE OpenAI chat-completions
     * wire messages. Returns a list:
     *
     *   - AssistantMessage   → exactly 1 entry (text + tool_calls combined).
     *   - ToolResultMessage  → N entries, one per tool_result block.
     *   - SystemMessage      → 1 entry.
     *   - UserMessage        → 1 entry.
     *
     * The actual encoding lives in `OpenAIChatEncoder` so that the same
     * canonical translation is shared with the Transcoder facade and
     * with any future cross-family handoff path. Subclasses that need
     * vendor-specific message encoding still get this protected hook
     * to override; the base implementation is a thin delegation.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function convertMessage(Message $message): array
    {
        return (new \SuperAgent\Conversation\Encoder\OpenAIChatEncoder())
            ->encode([$message]);
    }

    protected function convertTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $out[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name(),
                        'description' => $tool->description(),
                        'parameters' => $tool->inputSchema(),
                    ],
                ];
            }
        }
        return $out;
    }

    protected function parseSSEStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $buffer = '';
        $messageContent = '';
        /** @var array<int|string, array{id:string, name:string, arguments:string}> */
        $toolCallsByIndex = [];
        $usage = null;
        $stopReason = null;

        while (! $stream->eof()) {
            $chunk = $stream->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (strpos($line, 'data: ') !== 0) {
                    continue;
                }
                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    break 2;
                }
                $json = json_decode($data, true);
                if (! $json) {
                    continue;
                }

                // Check for DashScope-compat's `error_finish` BEFORE
                // touching the content accumulator — the terminating
                // chunk carries the error text in delta.content, and
                // we don't want that text mistaken for legitimate
                // response content on the way out via partialContent.
                $finishReasonEarly = $json['choices'][0]['finish_reason'] ?? null;
                if ($finishReasonEarly === 'error_finish') {
                    throw new \SuperAgent\Exceptions\StreamContentError(
                        provider: $this->providerName(),
                        partialContent: $messageContent,
                        errorMessage: $json['choices'][0]['delta']['content']
                            ?? $json['choices'][0]['message']['content']
                            ?? 'upstream error (no body)',
                    );
                }

                if (isset($json['choices'][0]['delta'])) {
                    $delta = $json['choices'][0]['delta'];

                    // `content` may arrive as '' in some chunks — skip
                    // empty strings so they don't inflate the message.
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $messageContent .= $delta['content'];
                        $handler?->emitText($delta['content'], $messageContent);
                    }

                    if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $toolCallDelta) {
                            if (! is_array($toolCallDelta)) {
                                continue;
                            }
                            // Most OpenAI-compat providers include an
                            // `index` on every chunk. Fall back to the
                            // delta's `id` if absent and finally to 0
                            // so a single-tool stream works even on
                            // servers that omit the field.
                            $idx = $toolCallDelta['index']
                                ?? $toolCallDelta['id']
                                ?? 0;
                            if (! is_int($idx)) {
                                $idx = (string) $idx;
                            }

                            if (! isset($toolCallsByIndex[$idx])) {
                                $toolCallsByIndex[$idx] = [
                                    'id' => '',
                                    'name' => '',
                                    'arguments' => '',
                                ];
                            }
                            $acc = &$toolCallsByIndex[$idx];

                            // id / name are usually only in the first
                            // chunk — keep the first non-empty value
                            // we see rather than letting a later empty
                            // chunk wipe them.
                            if (isset($toolCallDelta['id']) && $toolCallDelta['id'] !== '') {
                                $acc['id'] = (string) $toolCallDelta['id'];
                            }
                            if (isset($toolCallDelta['function']['name'])
                                && $toolCallDelta['function']['name'] !== ''
                            ) {
                                $acc['name'] = (string) $toolCallDelta['function']['name'];
                            }
                            if (isset($toolCallDelta['function']['arguments'])) {
                                $acc['arguments'] .= (string) $toolCallDelta['function']['arguments'];
                            }
                            unset($acc);
                        }
                    }
                }

                if (isset($json['usage'])) {
                    // Cached tokens on the OpenAI wire surface in two
                    // historical shapes — the legacy top-level
                    // `usage.cached_tokens` and the current
                    // `usage.prompt_tokens_details.cached_tokens`. Kimi
                    // emits the new shape; keeping both paths costs one
                    // fallback read and avoids silently losing the
                    // cache-read metric when an older provider shows up.
                    $cachedRead = (int) (
                        $json['usage']['prompt_tokens_details']['cached_tokens']
                        ?? $json['usage']['cached_tokens']
                        ?? 0
                    );
                    $usage = new Usage(
                        (int) ($json['usage']['prompt_tokens'] ?? 0),
                        (int) ($json['usage']['completion_tokens'] ?? 0),
                        cacheReadInputTokens: $cachedRead > 0 ? $cachedRead : null,
                    );
                }

                if (isset($json['choices'][0]['finish_reason'])) {
                    $stopReason = $this->mapStopReason($json['choices'][0]['finish_reason']);
                }
            }
        }

        $content = [];
        if ($messageContent !== '') {
            $content[] = new ContentBlock('text', $messageContent);
        }
        // Build one ContentBlock per assembled tool call. Each call's
        // `arguments` string has been accumulated across N chunks;
        // parse once, apply a cheap repair if the JSON is malformed
        // (some providers emit truncated argument strings when the
        // model hits max_tokens mid-tool-call), and emit a single
        // `onToolUse` event to the handler.
        foreach ($toolCallsByIndex as $idx => $acc) {
            $args = self::decodeToolArguments($acc['arguments']);
            $block = new ContentBlock(
                'tool_use',
                null,
                $acc['id'],
                $acc['name'],
                $args,
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
     * Decode an accumulated tool-call arguments string. Empty input
     * becomes `[]`; valid JSON object decodes normally; invalid JSON
     * gets one cheap repair attempt (append `}` for obvious truncation)
     * before giving up and returning an empty array — an empty arg
     * dict is a better signal to the agent loop than an unhandled
     * JSON-parse error escaping the SSE parser.
     *
     * @return array<string, mixed>
     */
    protected static function decodeToolArguments(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // One-shot repair: unclosed object → append `}`. Doesn't fix
        // deeper truncation (unclosed strings, missing commas) but
        // catches the most common max-tokens-mid-object case.
        if (substr_count($raw, '{') > substr_count($raw, '}')) {
            $decoded = json_decode($raw . str_repeat('}', substr_count($raw, '{') - substr_count($raw, '}')), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    protected function mapStopReason(string $finishReason): StopReason
    {
        return match ($finishReason) {
            'stop' => StopReason::EndTurn,
            'length' => StopReason::MaxTokens,
            'tool_calls', 'function_call' => StopReason::ToolUse,
            default => StopReason::EndTurn,
        };
    }

    protected function getRetryDelay(int $attempt, $response): float
    {
        if ($response->hasHeader('Retry-After')) {
            // Honour the server's hint exactly. Numeric seconds or an
            // HTTP-date per RFC 9110 §10.2.3. We stick to numeric seconds
            // (the only form any of our providers send). No jitter —
            // the server is telling us exactly when to retry.
            return (float) $response->getHeader('Retry-After')[0];
        }
        return $this->jitteredBackoff($attempt);
    }

    /**
     * Exponential backoff with multiplicative jitter in [0.9, 1.1] —
     * same shape as codex-rs's `retry::backoff()`. Jitter spreads out
     * simultaneous retries from parallel workers (Laravel queue with
     * N consumers retrying the same rate limit) so they don't
     * thundering-herd the next attempt.
     *
     * Base delay: 1s per legacy behaviour; grows 2^attempt with a
     * ±10% random factor. Floor 200ms so the first retry isn't
     * instantaneous; ceiling 60s so a misconfigured max_retries can't
     * produce an hours-long sleep.
     */
    protected function jitteredBackoff(int $attempt): float
    {
        $base = pow(2, max(1, $attempt));
        $jitter = mt_rand(90, 110) / 100.0;
        $delay = $base * $jitter;
        return max(0.2, min(60.0, $delay));
    }
}
