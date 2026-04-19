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
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\StreamingHandler;
use SuperAgent\Tools\Tool;

/**
 * Google Gemini (Generative Language API) provider.
 *
 * Endpoint: https://generativelanguage.googleapis.com/v1beta/models/{model}:streamGenerateContent?alt=sse
 * Auth:     x-goog-api-key header (AI Studio key) OR Authorization: Bearer (Vertex OAuth)
 *
 * Wire format differs from OpenAI/Anthropic on three axes that the conversion must honour:
 *   1. messages → contents[] with role "user"/"model" (not "assistant") and parts[]
 *   2. system prompt → top-level systemInstruction, NOT a contents[] entry
 *   3. tool schemas → tools[0].functionDeclarations[] with OpenAPI-3.0 subset schema
 */
class GeminiProvider implements LLMProvider
{
    protected Client $client;

    protected string $apiKey;

    protected string $model;

    protected int $maxTokens;

    protected int $maxRetries;

    public function __construct(array $config)
    {
        $apiKey = $config['api_key'] ?? null;
        if (empty($apiKey)) {
            throw new ProviderException('API key is required', 'gemini');
        }
        $this->apiKey = $apiKey;

        $baseUrl = rtrim($config['base_url'] ?? 'https://generativelanguage.googleapis.com', '/') . '/';
        $this->model = $config['model'] ?? 'gemini-2.0-flash';
        $this->maxTokens = $config['max_tokens'] ?? 8192;
        $this->maxRetries = $config['max_retries'] ?? 3;

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'content-type' => 'application/json',
            ],
            'timeout' => 300,
        ]);
    }

    public function chat(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): Generator {
        $body = $this->buildRequestBody($messages, $tools, $systemPrompt, $options);
        $model = $options['model'] ?? $this->model;
        $path = 'v1beta/models/' . rawurlencode($model) . ':streamGenerateContent?alt=sse';

        $attempt = 0;
        while (true) {
            try {
                $response = $this->client->post($path, [
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
                    message: $responseBody['error']['message'] ?? $e->getMessage(),
                    provider: 'gemini',
                    statusCode: $status,
                    responseBody: $responseBody,
                    previous: $e,
                );
            } catch (GuzzleException $e) {
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
                    continue;
                }
                throw new ProviderException($e->getMessage(), 'gemini', previous: $e);
            }
        }

        yield from $this->parseSSEStream($response->getBody(), $options['streaming_handler'] ?? null);
    }

    public function formatMessages(array $messages): array
    {
        // Build toolUseId → toolName map from prior assistant messages so
        // functionResponse entries can carry the name Gemini requires.
        $toolNames = [];
        foreach ($messages as $m) {
            if ($m instanceof AssistantMessage) {
                foreach ($m->content as $block) {
                    if ($block->type === 'tool_use' && $block->toolUseId && $block->toolName) {
                        $toolNames[$block->toolUseId] = $block->toolName;
                    }
                }
            }
        }

        $contents = [];
        foreach ($messages as $message) {
            $converted = $this->convertMessage($message, $toolNames);
            if ($converted !== null) {
                $contents[] = $converted;
            }
        }

        return $contents;
    }

    public function formatTools(array $tools): array
    {
        if (empty($tools)) {
            return [];
        }

        $declarations = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }
            $def = $tool->toDefinition();
            $declarations[] = [
                'name' => $def['name'],
                'description' => $def['description'],
                'parameters' => $this->sanitizeSchema($def['input_schema'] ?? []),
            ];
        }

        return [['functionDeclarations' => $declarations]];
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
        return 'gemini';
    }

    protected function buildRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        $body = [
            'contents' => $this->formatMessages($messages),
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? $this->maxTokens,
            ],
        ];

        if (isset($options['temperature'])) {
            $body['generationConfig']['temperature'] = $options['temperature'];
        }
        if (isset($options['top_p'])) {
            $body['generationConfig']['topP'] = $options['top_p'];
        }
        if (isset($options['top_k'])) {
            $body['generationConfig']['topK'] = $options['top_k'];
        }
        if (isset($options['stop_sequences'])) {
            $body['generationConfig']['stopSequences'] = $options['stop_sequences'];
        }

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $body['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        if (! empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        return $body;
    }

    /**
     * Convert one Message into a Gemini `contents[]` entry, or null if it should be skipped.
     *
     * @param  array<string, string>  $toolNames  Map of toolUseId → toolName
     */
    protected function convertMessage(Message $message, array $toolNames): ?array
    {
        if ($message instanceof AssistantMessage) {
            $parts = [];
            foreach ($message->content as $block) {
                if ($block->type === 'text' && $block->text !== null && $block->text !== '') {
                    $parts[] = ['text' => $block->text];
                } elseif ($block->type === 'tool_use') {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $block->toolName ?? '',
                            'args' => $block->toolInput ?? (object) [],
                        ],
                    ];
                }
                // thinking blocks are dropped — Gemini has no equivalent
            }
            if (empty($parts)) {
                return null;
            }

            return ['role' => 'model', 'parts' => $parts];
        }

        if ($message instanceof ToolResultMessage) {
            $parts = [];
            foreach ($message->content as $block) {
                if ($block->type !== 'tool_result') {
                    continue;
                }
                $name = $toolNames[$block->toolUseId] ?? '';
                $parts[] = [
                    'functionResponse' => [
                        'name' => $name,
                        'response' => $this->wrapFunctionResponse($block->content, (bool) $block->isError),
                    ],
                ];
            }
            if (empty($parts)) {
                return null;
            }

            return ['role' => 'user', 'parts' => $parts];
        }

        // Plain user message: content is string|array
        $rawContent = $message->toArray()['content'] ?? '';
        $parts = $this->convertPlainUserContent($rawContent, $toolNames);
        if (empty($parts)) {
            return null;
        }

        return ['role' => 'user', 'parts' => $parts];
    }

    /**
     * A user message's content may be a bare string or an array of internal blocks
     * (including tool_result entries when callers assemble them directly). Normalize
     * into Gemini `parts[]`.
     *
     * @param  array<string, string>  $toolNames
     */
    protected function convertPlainUserContent(string|array $content, array $toolNames): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['text' => $content]];
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                if ($item !== '') {
                    $parts[] = ['text' => $item];
                }
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? null;
            if ($type === 'text') {
                $text = $item['text'] ?? '';
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }
            } elseif ($type === 'tool_result') {
                $id = $item['tool_use_id'] ?? '';
                $parts[] = [
                    'functionResponse' => [
                        'name' => $toolNames[$id] ?? '',
                        'response' => $this->wrapFunctionResponse(
                            $item['content'] ?? '',
                            (bool) ($item['is_error'] ?? false),
                        ),
                    ],
                ];
            }
        }

        return $parts;
    }

    /**
     * Gemini requires functionResponse.response to be a JSON object. Wrap string
     * payloads under a `content` key, mark errors under `error` so the model can
     * distinguish failures.
     */
    protected function wrapFunctionResponse(?string $content, bool $isError): array
    {
        $payload = $content ?? '';
        $decoded = json_decode($payload, true);
        $body = is_array($decoded) ? $decoded : ['content' => $payload];

        if ($isError) {
            $body['error'] = true;
        }

        return $body;
    }

    /**
     * Strip fields Gemini's OpenAPI-3.0 subset rejects and recurse into nested schemas.
     * Accepted keywords: type, format, description, nullable, items, properties,
     * required, enum, minimum, maximum, minItems, maxItems, minLength, maxLength.
     */
    protected function sanitizeSchema(mixed $schema): array
    {
        if (! is_array($schema)) {
            return ['type' => 'object', 'properties' => (object) []];
        }

        $allowed = [
            'type', 'format', 'description', 'nullable', 'enum',
            'items', 'properties', 'required',
            'minimum', 'maximum', 'minItems', 'maxItems',
            'minLength', 'maxLength',
        ];

        $out = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }
            $value = $schema[$key];

            if ($key === 'properties') {
                if (is_object($value)) {
                    $value = (array) $value;
                }
                if (! is_array($value) || empty($value)) {
                    $out['properties'] = (object) [];
                    continue;
                }
                $props = [];
                foreach ($value as $propName => $propSchema) {
                    $props[$propName] = $this->sanitizeSchema($propSchema);
                }
                $out['properties'] = $props;
            } elseif ($key === 'items') {
                $out['items'] = $this->sanitizeSchema($value);
            } else {
                $out[$key] = $value;
            }
        }

        if (! isset($out['type'])) {
            $out['type'] = 'object';
        }
        if ($out['type'] === 'object' && ! isset($out['properties'])) {
            $out['properties'] = (object) [];
        }

        return $out;
    }

    protected function parseSSEStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $message = new AssistantMessage();
        $accumulatedText = '';
        $toolCalls = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $stopReason = null;

        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = rtrim($line, "\r");
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

                $handler?->emitRawEvent('chunk', $event);

                if (isset($event['error'])) {
                    throw new ProviderException(
                        $event['error']['message'] ?? 'Stream error',
                        'gemini',
                    );
                }

                foreach ($event['candidates'] ?? [] as $candidate) {
                    foreach ($candidate['content']['parts'] ?? [] as $part) {
                        if (isset($part['text'])) {
                            $accumulatedText .= $part['text'];
                            $handler?->emitText($part['text'], $accumulatedText);
                        } elseif (isset($part['functionCall'])) {
                            $call = $part['functionCall'];
                            $toolCalls[] = [
                                'name' => $call['name'] ?? '',
                                'args' => $call['args'] ?? [],
                            ];
                        }
                    }
                    if (isset($candidate['finishReason'])) {
                        $stopReason = $this->mapStopReason($candidate['finishReason']);
                    }
                }

                if (isset($event['usageMetadata'])) {
                    $inputTokens = $event['usageMetadata']['promptTokenCount'] ?? $inputTokens;
                    $outputTokens = $event['usageMetadata']['candidatesTokenCount'] ?? $outputTokens;
                }
            }
        }

        if ($accumulatedText !== '') {
            $message->content[] = ContentBlock::text($accumulatedText);
        }

        // Gemini does not issue tool-call IDs; synthesize one so downstream
        // correlation (tool_result → tool_use) still works.
        foreach ($toolCalls as $i => $call) {
            $id = 'gemini_' . bin2hex(random_bytes(6)) . '_' . $i;
            $block = ContentBlock::toolUse(
                $id,
                $call['name'],
                is_array($call['args']) ? $call['args'] : [],
            );
            $message->content[] = $block;
            $handler?->emitToolUse($block);
        }

        if ($stopReason === null) {
            $stopReason = ! empty($toolCalls) ? StopReason::ToolUse : StopReason::EndTurn;
        }
        $message->stopReason = $stopReason;
        $message->usage = new Usage($inputTokens, $outputTokens);

        yield $message;
    }

    protected function mapStopReason(string $reason): StopReason
    {
        return match ($reason) {
            'STOP' => StopReason::EndTurn,
            'MAX_TOKENS' => StopReason::MaxTokens,
            'SAFETY', 'RECITATION', 'OTHER' => StopReason::EndTurn,
            default => StopReason::EndTurn,
        };
    }

    protected function getRetryDelay(int $attempt, $response): float
    {
        $retryAfter = $response->getHeaderLine('retry-after');
        if ($retryAfter !== '') {
            return (float) $retryAfter;
        }

        return min(pow(2, $attempt - 1), 30);
    }
}
