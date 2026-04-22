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
        $this->maxRetries = (int) ($config['max_retries'] ?? 3);

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
                ]);
                break;
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                    $attempt++;
                    $delay = $this->getRetryDelay($attempt, $e->getResponse());
                    usleep((int) ($delay * 1_000_000));
                    continue;
                }

                throw new ProviderException(
                    $responseBody['error']['message'] ?? $e->getMessage(),
                    $this->providerName(),
                    $status,
                    $responseBody,
                    previous: $e,
                );
            } catch (GuzzleException $e) {
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
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
        $formatted = [];
        foreach ($messages as $message) {
            $formatted[] = $this->convertMessage($message);
        }
        return $formatted;
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

        foreach ($messages as $message) {
            $out[] = $this->convertMessage($message);
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

        return $body;
    }

    protected function convertMessage(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            $content = [];
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $content[] = ['type' => 'text', 'text' => $block->text];
                } elseif ($block->type === 'tool_use') {
                    return [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => $block->id,
                            'type' => 'function',
                            'function' => [
                                'name' => $block->name,
                                'arguments' => json_encode($block->input),
                            ],
                        ]],
                    ];
                }
            }
            return ['role' => 'assistant', 'content' => $content];
        }

        return ['role' => $message->role, 'content' => $message->content];
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
        $toolCalls = [];
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

                if (isset($json['choices'][0]['delta'])) {
                    $delta = $json['choices'][0]['delta'];

                    if (isset($delta['content'])) {
                        $messageContent .= $delta['content'];
                        $handler?->onText($delta['content']);
                    }

                    if (isset($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $toolCall) {
                            if (isset($toolCall['function'])) {
                                $toolCalls[] = $toolCall;
                                $handler?->onToolUse(
                                    $toolCall['id'] ?? '',
                                    $toolCall['function']['name'] ?? '',
                                    json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
                                );
                            }
                        }
                    }
                }

                if (isset($json['usage'])) {
                    $usage = new Usage(
                        $json['usage']['prompt_tokens'] ?? 0,
                        $json['usage']['completion_tokens'] ?? 0,
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
        foreach ($toolCalls as $toolCall) {
            $content[] = new ContentBlock(
                'tool_use',
                null,
                $toolCall['id'] ?? '',
                $toolCall['function']['name'] ?? '',
                json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            );
        }

        yield new AssistantMessage(
            content: $content,
            usage: $usage,
            stopReason: $stopReason ?? StopReason::EndTurn,
        );
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
            return (float) $response->getHeader('Retry-After')[0];
        }
        return pow(2, $attempt);
    }
}
