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
use SuperAgent\Thinking\ThinkingConfig;
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
        $this->model = $config['model'] ?? 'gemini-3.5-flash';
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
        return (new \SuperAgent\Conversation\Transcoder())
            ->encode($messages, \SuperAgent\Conversation\WireFamily::Gemini);
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

        // Thinking / reasoning config — Gemini 3.x exposes `thinkingConfig` with
        // either an integer `thinkingBudget` (token budget) or a `thinkingLevel`
        // enum (LOW/HIGH). We accept both an explicit `thinking_config` array
        // (escape hatch for advanced callers) and a generic ThinkingConfig the
        // rest of the SDK already speaks via the same `thinking` option.
        $thinkingCfg = $this->buildThinkingConfig(
            $options['thinking_config'] ?? null,
            $options['thinking'] ?? null,
            $body['model'] ?? ($options['model'] ?? $this->model),
        );
        if ($thinkingCfg !== null) {
            $body['generationConfig']['thinkingConfig'] = $thinkingCfg;
        }

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $body['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        if (! empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        // Grounding: enable Google Search and/or URL Context tools alongside
        // user-declared function tools. Gemini accepts multiple tool entries
        // in the `tools` array — one per capability.
        if (! empty($options['grounding']) || ! empty($options['google_search'])) {
            $body['tools'] = array_merge($body['tools'] ?? [], [['googleSearch' => (object) []]]);
        }
        if (! empty($options['url_context'])) {
            $body['tools'] = array_merge($body['tools'] ?? [], [['urlContext' => (object) []]]);
        }

        return $body;
    }

    /**
     * Translate cross-provider ThinkingConfig (or raw options) into Gemini's
     * `generationConfig.thinkingConfig` shape. Returns null when thinking is
     * disabled or unsupported by the target model.
     *
     * Accepts (in priority order):
     *   1. `thinking_config` raw array → returned as-is (camelCase preserved)
     *   2. `thinking` instance of \SuperAgent\Thinking\ThinkingConfig → mapped:
     *        adaptive → {thinkingLevel: 'HIGH', includeThoughts: true}
     *        enabled  → {thinkingBudget: N, includeThoughts: true}
     *        disabled → null
     */
    protected function buildThinkingConfig(mixed $raw, mixed $generic, string $model): ?array
    {
        if (is_array($raw) && ! empty($raw)) {
            return $raw;
        }

        if ($generic instanceof ThinkingConfig) {
            if ($generic->getMode() === 'disabled') {
                return null;
            }
            if (! $this->modelSupportsThinking($model)) {
                return null;
            }
            if ($generic->isAdaptive()) {
                return ['thinkingLevel' => 'HIGH', 'includeThoughts' => true];
            }
            return ['thinkingBudget' => $generic->getBudgetTokens(), 'includeThoughts' => true];
        }

        return null;
    }

    /**
     * True for Gemini 3.x Pro / 3.x Flash with thinking-enabled tier and the
     * legacy 2.0 "thinking-exp" preview. Mirrors gemini-cli's modelDefinitions.
     */
    protected function modelSupportsThinking(string $model): bool
    {
        $m = strtolower($model);
        if (str_contains($m, 'thinking')) {
            return true;
        }
        // Gemini 3.x: pro tier ships thinking, flash tier in 3.5+
        if (preg_match('/^gemini-3(\.\d+)?-pro/', $m)) {
            return true;
        }
        if (preg_match('/^gemini-3\.\d+-flash(?!-lite)/', $m) || str_starts_with($m, 'gemini-3.5-flash')) {
            return true;
        }
        return false;
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
        $accumulatedThought = '';
        $toolCalls = [];
        $groundingSources = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $thinkingTokens = 0;
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
                        // Gemini 3.x thinking parts carry `thought: true` and use
                        // `text` for the thought body. They must be surfaced as
                        // thinking content blocks (not visible text) so the
                        // downstream renderer/transcoder treats them correctly.
                        if (! empty($part['thought']) && isset($part['text'])) {
                            $accumulatedThought .= $part['text'];
                            continue;
                        }
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

                    // Capture grounding citations (Google Search / URL Context).
                    $grounding = $candidate['groundingMetadata'] ?? null;
                    if (is_array($grounding) && ! empty($grounding['groundingChunks'])) {
                        foreach ($grounding['groundingChunks'] as $chunk) {
                            $web = $chunk['web'] ?? null;
                            if (is_array($web) && ! empty($web['uri'])) {
                                $groundingSources[] = [
                                    'uri' => (string) $web['uri'],
                                    'title' => (string) ($web['title'] ?? ''),
                                ];
                            }
                        }
                    }

                    if (isset($candidate['finishReason'])) {
                        $stopReason = $this->mapStopReason($candidate['finishReason']);
                    }
                }

                if (isset($event['usageMetadata'])) {
                    $inputTokens = $event['usageMetadata']['promptTokenCount'] ?? $inputTokens;
                    $outputTokens = $event['usageMetadata']['candidatesTokenCount'] ?? $outputTokens;
                    $thinkingTokens = $event['usageMetadata']['thoughtsTokenCount'] ?? $thinkingTokens;
                }
            }
        }

        // Emit thinking block first so renderers can show "reasoning" before
        // the visible answer, matching the Anthropic ordering convention.
        if ($accumulatedThought !== '') {
            $message->content[] = ContentBlock::thinking($accumulatedThought);
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
        // Surface thinking tokens via output usage (closest existing slot;
        // Usage doesn't yet carry a separate "thinking" counter). Grounding
        // sources are stashed on the assistant message metadata so callers
        // that opted into search/url_context can render citations.
        $message->usage = new Usage($inputTokens, $outputTokens + $thinkingTokens);
        if (! empty($groundingSources) && property_exists($message, 'metadata')) {
            $message->metadata = array_merge(
                is_array($message->metadata ?? null) ? $message->metadata : [],
                ['grounding_sources' => $groundingSources],
            );
        }

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
