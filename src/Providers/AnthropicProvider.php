<?php

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
use SuperAgent\StreamingHandler;
use SuperAgent\Prompt\SystemPromptBuilder;
use SuperAgent\Thinking\ThinkingConfig;
use SuperAgent\Tools\Tool;

class AnthropicProvider implements LLMProvider
{
    protected Client $client;

    protected string $model;

    protected string $apiVersion;

    protected int $maxTokens;

    protected int $maxRetries;

    public function __construct(array $config)
    {
        $apiKey = $config['api_key'] ?? throw new ProviderException('API key is required', 'anthropic');
        // Guzzle follows RFC 3986 when resolving request paths against base_uri.
        // Without a trailing slash, an absolute path like '/v1/messages' replaces
        // the entire path component: 'https://host/prefix' + '/v1/messages' =>
        // 'https://host/v1/messages' (the '/prefix' is lost).
        // With a trailing slash, 'v1/messages' is treated as relative and appended:
        // 'https://host/prefix/' + 'v1/messages' => 'https://host/prefix/v1/messages'.
        $baseUrl = rtrim($config['base_url'] ?? 'https://api.anthropic.com', '/') . '/';
        $this->apiVersion = $config['api_version'] ?? '2023-06-01';
        $this->model = $config['model'] ?? 'claude-sonnet-4-20250514';
        $this->maxTokens = $config['max_tokens'] ?? 8192;
        $this->maxRetries = $config['max_retries'] ?? 3;

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => $this->apiVersion,
                'content-type' => 'application/json',
            ],
            'timeout' => 300,
        ]);
    }

    /**
     * @inheritDoc
     */
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
                $response = $this->client->post('v1/messages', [
                    'json' => $body,
                    'stream' => true,
                ]);
                break;
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                // Retry on rate limit (429) or server errors (5xx)
                if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                    $attempt++;
                    $delay = $this->getRetryDelay($attempt, $e->getResponse());
                    usleep((int) ($delay * 1_000_000));
                    continue;
                }

                throw new ProviderException(
                    $responseBody['error']['message'] ?? $e->getMessage(),
                    'anthropic',
                    $status,
                    $responseBody,
                    $e,
                );
            } catch (GuzzleException $e) {
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
                    continue;
                }
                throw new ProviderException($e->getMessage(), 'anthropic', previous: $e);
            }
        }

        yield from $this->parseSSEStream($response->getBody(), $options['streaming_handler'] ?? null);
    }

    public function formatMessages(array $messages): array
    {
        return array_map(fn (Message $m) => $m->toArray(), $messages);
    }

    public function formatTools(array $tools): array
    {
        return array_map(function (Tool $t) {
            $def = $t->toDefinition();
            // Anthropic API does not accept extra fields like 'category'
            unset($def['category']);

            return $def;
        }, $tools);
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
        return 'anthropic';
    }

    /**
     * Format the system prompt for the Anthropic API.
     *
     * If the prompt contains the cache boundary marker, splits it into
     * static (cacheable) and dynamic blocks with appropriate cache_control.
     * This enables prompt caching: the static prefix stays cached across
     * turns while the dynamic suffix can change without busting the cache.
     *
     * @return string|array The formatted system prompt (string or block array)
     */
    protected function formatSystemPrompt(string $systemPrompt, bool $enableCaching): string|array
    {
        $boundary = SystemPromptBuilder::CACHE_BOUNDARY;

        if (! $enableCaching || ! str_contains($systemPrompt, $boundary)) {
            // No caching or no boundary marker — return as plain string
            return $systemPrompt;
        }

        // Split at boundary marker
        $parts = explode($boundary, $systemPrompt, 2);
        $staticContent = trim($parts[0]);
        $dynamicContent = trim($parts[1] ?? '');

        $blocks = [];

        if ($staticContent !== '') {
            $blocks[] = [
                'type' => 'text',
                'text' => $staticContent,
                'cache_control' => ['type' => 'ephemeral'],
            ];
        }

        if ($dynamicContent !== '') {
            $blocks[] = [
                'type' => 'text',
                'text' => $dynamicContent,
            ];
        }

        return ! empty($blocks) ? $blocks : $systemPrompt;
    }

    protected function buildRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        $body = [
            'model' => $options['model'] ?? $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        if ($systemPrompt !== null) {
            $body['system'] = $this->formatSystemPrompt(
                $systemPrompt,
                $options['prompt_caching']
                    ?? \SuperAgent\Config\ExperimentalFeatures::enabled('prompt_cache_break_detection'),
            );
        }

        if (! empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        // Pass through extra options (temperature, top_p, etc.)
        foreach (['temperature', 'top_p', 'top_k', 'stop_sequences', 'metadata'] as $key) {
            if (isset($options[$key])) {
                $body[$key] = $options[$key];
            }
        }

        // Response prefill: inject partial assistant message to guide output
        if (isset($options['assistant_prefill']) && is_string($options['assistant_prefill'])) {
            $body['messages'][] = [
                'role' => 'assistant',
                'content' => $options['assistant_prefill'],
            ];
        }

        // Extended thinking support
        $thinkingConfig = $options['thinking'] ?? null;
        if ($thinkingConfig instanceof ThinkingConfig) {
            $thinkingParam = $thinkingConfig->toApiParameter($body['model']);
            if ($thinkingParam !== null) {
                $body['thinking'] = $thinkingParam;
                // When thinking is enabled, temperature must not be set (API requirement)
                unset($body['temperature']);
            }
        } elseif (isset($options['thinking_budget_tokens']) && (int) $options['thinking_budget_tokens'] > 0) {
            // Shorthand: pass thinking_budget_tokens directly
            $model = $body['model'];
            if (ThinkingConfig::modelSupportsThinking($model)) {
                $body['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => (int) $options['thinking_budget_tokens'],
                ];
                unset($body['temperature']);
            }
        }

        return $body;
    }

    /**
     * Parse the SSE stream and yield AssistantMessage(s).
     */
    protected function parseSSEStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $message = new AssistantMessage();
        $currentBlockIndex = -1;
        $currentBlockType = null;
        $currentText = '';
        $currentToolJson = '';
        $currentToolId = null;
        $currentToolName = null;
        $inputTokens = 0;
        $outputTokens = 0;

        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, ':')) {
                    continue;
                }

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    break 2;
                }

                $event = json_decode($data, true);
                if ($event === null) {
                    continue;
                }

                $handler?->emitRawEvent($event['type'] ?? '', $event);

                switch ($event['type'] ?? '') {
                    case 'message_start':
                        $inputTokens = $event['message']['usage']['input_tokens'] ?? 0;
                        break;

                    case 'content_block_start':
                        $currentBlockIndex = $event['index'] ?? $currentBlockIndex + 1;
                        $block = $event['content_block'] ?? [];
                        $currentBlockType = $block['type'] ?? 'text';
                        if ($currentBlockType === 'tool_use') {
                            $currentToolId = $block['id'] ?? null;
                            $currentToolName = $block['name'] ?? null;
                            $currentToolJson = '';
                        } else {
                            $currentText = $block['text'] ?? $block['thinking'] ?? '';
                        }
                        break;

                    case 'content_block_delta':
                        $delta = $event['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            $chunk = $delta['text'] ?? '';
                            $currentText .= $chunk;
                            $handler?->emitText($chunk, $currentText);
                        } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                            $currentToolJson .= $delta['partial_json'] ?? '';
                        } elseif (($delta['type'] ?? '') === 'thinking_delta') {
                            $chunk = $delta['thinking'] ?? '';
                            $currentText .= $chunk;
                            $handler?->emitThinking($chunk, $currentText);
                        }
                        break;

                    case 'content_block_stop':
                        if ($currentToolId !== null) {
                            $block = ContentBlock::toolUse(
                                $currentToolId,
                                $currentToolName ?? '',
                                json_decode($currentToolJson, true) ?? [],
                            );
                            $message->content[] = $block;
                            $handler?->emitToolUse($block);
                            $currentToolId = null;
                            $currentToolName = null;
                            $currentToolJson = '';
                        } elseif ($currentText !== '') {
                            if ($currentBlockType === 'thinking') {
                                $message->content[] = ContentBlock::thinking($currentText);
                            } else {
                                $message->content[] = ContentBlock::text($currentText);
                            }
                            $currentText = '';
                        }
                        $currentBlockType = null;
                        break;

                    case 'message_delta':
                        $delta = $event['delta'] ?? [];
                        if (isset($delta['stop_reason'])) {
                            $message->stopReason = StopReason::tryFrom($delta['stop_reason']);
                        }
                        $outputTokens += $event['usage']['output_tokens'] ?? 0;
                        break;

                    case 'message_stop':
                        break;

                    case 'error':
                        throw new ProviderException(
                            $event['error']['message'] ?? 'Stream error',
                            'anthropic',
                        );
                }
            }
        }

        $message->usage = new Usage($inputTokens, $outputTokens);

        yield $message;
    }

    protected function getRetryDelay(int $attempt, $response): float
    {
        // Check for Retry-After header
        $retryAfter = $response->getHeaderLine('retry-after');
        if ($retryAfter !== '') {
            return (float) $retryAfter;
        }

        // Exponential backoff: 1s, 2s, 4s, ...
        return min(pow(2, $attempt - 1), 30);
    }
}
