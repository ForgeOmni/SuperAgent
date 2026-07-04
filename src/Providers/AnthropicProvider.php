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
use SuperAgent\Providers\Capabilities\SupportsReasoningEffort;
use SuperAgent\Providers\Capabilities\SupportsThinking;
use SuperAgent\Thinking\ThinkingConfig;
use SuperAgent\Tools\Tool;

/**
 * Anthropic Messages API client.
 *
 * Talks to `https://api.anthropic.com` by default, but `$config['base_url']`
 * lets the same provider front any Anthropic-wire-compatible endpoint:
 *
 *   - DeepSeek V4 — `https://api.deepseek.com/anthropic` (DEEPSEEK_API_KEY).
 *     V4 is wire-compatible end-to-end, so configuring
 *     `provider=anthropic, base_url=https://api.deepseek.com/anthropic,
 *      api_key=$DEEPSEEK_API_KEY, model=deepseek-v4-pro` works without a
 *     dedicated DeepSeekProvider on the Anthropic path. Use the OpenAI-compat
 *     `DeepSeekProvider` when you want the OpenAI wire shape instead.
 *   - Self-hosted Anthropic gateways / proxies.
 *   - Bedrock / Vertex Anthropic models go through their own providers
 *     (BedrockProvider) — this base_url override is for direct-HTTP gateways.
 */
class AnthropicProvider implements LLMProvider, SupportsThinking, SupportsReasoningEffort
{
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        // Adaptive-only models (Opus 4.6/4.7/4.8, Sonnet 4.6, Fable 5) take
        // `{type: adaptive}` and 400 on an explicit `budget_tokens`; older
        // models take a fixed token budget.
        if (ThinkingConfig::modelSupportsAdaptiveThinking($this->model)) {
            return ['thinking' => ['type' => 'adaptive']];
        }

        // Anthropic extended thinking: explicit budget in tokens.
        return ['thinking' => [
            'type' => 'enabled',
            'budget_tokens' => max(1, $budgetTokens),
        ]];
    }

    /**
     * Anthropic's GA effort dial → `output_config.effort` ∈
     * low | medium | high | xhigh | max. Supported on Fable 5, Opus 4.5+, and
     * Sonnet 4.6; emitted only for those models so a stray `reasoning_effort`
     * on an unsupported model (Haiku, older Sonnet) never 400s the request.
     * "off"/unknown yield [] — there is no effort-off on Anthropic (thinking is
     * controlled separately, and is always on for Fable 5).
     *
     * @return array<string, mixed>
     */
    public function reasoningEffortFragment(string $effort): array
    {
        if (! self::modelSupportsEffort($this->model)) {
            return [];
        }

        $level = match (strtolower(trim($effort))) {
            'low', 'minimal' => 'low',
            'medium', 'mid' => 'medium',
            'high' => 'high',
            'xhigh' => 'xhigh',
            'max', 'highest' => 'max',
            default => null,
        };

        return $level === null ? [] : ['output_config' => ['effort' => $level]];
    }

    /**
     * Models that accept the `output_config.effort` dial (GA, no beta header):
     * Fable 5, Opus 4.5 / 4.6 / 4.7 / 4.8, and Sonnet 4.6.
     */
    protected static function modelSupportsEffort(string $model): bool
    {
        $model = strtolower($model);
        foreach (['claude-fable', 'fable-5', 'claude-sonnet-5', 'sonnet-5', 'opus-4-5', 'opus-4-6', 'opus-4-7', 'opus-4-8', 'sonnet-4-6'] as $needle) {
            if (str_contains($model, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Claude 5 generation (Fable 5, Sonnet 5) and the Opus 4.7 / 4.8 family
     * reject `temperature`, `top_p`, and `top_k` with a 400 (sampling params were
     * removed). Prefill and thinking `budget_tokens` are handled via
     * ThinkingConfig::modelSupportsAdaptiveThinking().
     */
    protected static function modelRejectsSamplingParams(string $model): bool
    {
        $model = strtolower($model);
        foreach (['claude-fable', 'fable-5', 'claude-sonnet-5', 'sonnet-5', 'opus-4-7', 'opus-4-8'] as $needle) {
            if (str_contains($model, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected Client $client;

    protected string $model;

    protected string $apiVersion;

    protected int $maxTokens;

    protected int $maxRetries;

    protected bool $oauth = false;

    /**
     * Anthropic's OAuth endpoint rejects requests whose system prompt does not
     * begin with this exact string — it's how they gate Claude Code OAuth
     * tokens to official-CLI traffic only.
     */
    private const OAUTH_SYSTEM_PREFIX = "You are Claude Code, Anthropic's official CLI for Claude.";

    public function __construct(array $config)
    {
        $authMode = $config['auth_mode'] ?? (isset($config['access_token']) ? 'oauth' : 'api_key');
        $accessToken = $config['access_token'] ?? null;
        $apiKey = $config['api_key'] ?? null;

        if ($authMode === 'oauth') {
            if (empty($accessToken)) {
                throw new ProviderException('OAuth access_token is required', 'anthropic');
            }
        } else {
            if (empty($apiKey)) {
                throw new ProviderException('API key is required', 'anthropic');
            }
        }

        // Guzzle follows RFC 3986 when resolving request paths against base_uri.
        // Without a trailing slash, an absolute path like '/v1/messages' replaces
        // the entire path component: 'https://host/prefix' + '/v1/messages' =>
        // 'https://host/v1/messages' (the '/prefix' is lost).
        // With a trailing slash, 'v1/messages' is treated as relative and appended:
        // 'https://host/prefix/' + 'v1/messages' => 'https://host/prefix/v1/messages'.
        $baseUrl = rtrim($config['base_url'] ?? 'https://api.anthropic.com', '/') . '/';
        $this->apiVersion = $config['api_version'] ?? '2023-06-01';
        $this->model = $config['model'] ?? 'claude-opus-4-8';
        $this->maxTokens = $config['max_tokens'] ?? 8192;
        $this->maxRetries = $config['max_retries'] ?? 3;

        // Claude Code OAuth tokens only authorize subscription-era models. Rewrite
        // legacy model ids (e.g. claude-3-5-sonnet-20241022) that the API will
        // reject with a confusing 429 "rate_limit_error".
        if ($authMode === 'oauth' && $this->isLegacyModel($this->model)) {
            $this->model = 'claude-opus-4-8';
        }

        $headers = [
            'anthropic-version' => $this->apiVersion,
            'content-type' => 'application/json',
        ];
        if ($authMode === 'oauth') {
            $this->oauth = true;
            $headers['authorization'] = 'Bearer ' . $accessToken;
            // Required when calling the Messages API with a Claude Code OAuth token.
            $headers['anthropic-beta'] = $config['anthropic_beta'] ?? 'oauth-2025-04-20';
        } else {
            $headers['x-api-key'] = $apiKey;
        }

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => $headers,
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
                    message: $responseBody['error']['message'] ?? $e->getMessage(),
                    provider: 'anthropic',
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
                throw new ProviderException($e->getMessage(), 'anthropic', previous: $e);
            }
        }

        yield from $this->parseSSEStream($response->getBody(), $options['streaming_handler'] ?? null);
    }

    public function formatMessages(array $messages): array
    {
        return (new \SuperAgent\Conversation\Transcoder())
            ->encode($messages, \SuperAgent\Conversation\WireFamily::Anthropic);
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

    protected function isLegacyModel(string $model): bool
    {
        return str_starts_with($model, 'claude-3')
            || str_starts_with($model, 'claude-2')
            || str_starts_with($model, 'claude-instant');
    }

    /**
     * When using Claude Code OAuth tokens the API rejects requests whose first
     * system block doesn't begin with the required identity string. Prepend a
     * dedicated block so the caller's real system prompt is preserved after it.
     */
    protected function ensureOAuthSystemPrefix(string|array $system): string|array
    {
        if (is_string($system)) {
            if (str_starts_with($system, self::OAUTH_SYSTEM_PREFIX)) {
                return $system;
            }
            return [
                ['type' => 'text', 'text' => self::OAUTH_SYSTEM_PREFIX],
                ['type' => 'text', 'text' => $system],
            ];
        }

        $first = $system[0] ?? null;
        $firstText = is_array($first) ? ($first['text'] ?? '') : '';
        if (is_string($firstText) && str_starts_with($firstText, self::OAUTH_SYSTEM_PREFIX)) {
            return $system;
        }
        return array_merge(
            [['type' => 'text', 'text' => self::OAUTH_SYSTEM_PREFIX]],
            $system,
        );
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
        } elseif ($this->oauth) {
            // OAuth requires a system prompt; supply the required prefix alone.
            $body['system'] = self::OAUTH_SYSTEM_PREFIX;
        }

        if ($this->oauth) {
            $body['system'] = $this->ensureOAuthSystemPrefix($body['system']);
        }

        if (! empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        $model = $body['model'];

        // Pass through extra options (temperature, top_p, etc.). Fable 5 /
        // Opus 4.7 / 4.8 reject the sampling params with a 400, so drop them
        // for those models (steer via prompting / output_config.effort instead).
        $passthrough = ['stop_sequences', 'metadata'];
        if (! self::modelRejectsSamplingParams($model)) {
            array_unshift($passthrough, 'temperature', 'top_p', 'top_k');
        }
        foreach ($passthrough as $key) {
            if (isset($options[$key])) {
                $body[$key] = $options[$key];
            }
        }

        // Response prefill: inject partial assistant message to guide output.
        // The 4.6+ family and Fable 5 reject a trailing assistant prefill (400),
        // so skip it on those models.
        if (isset($options['assistant_prefill'])
            && is_string($options['assistant_prefill'])
            && ! ThinkingConfig::modelSupportsAdaptiveThinking($model)) {
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
            // Shorthand: pass thinking_budget_tokens directly.
            if (ThinkingConfig::modelSupportsAdaptiveThinking($model)) {
                // Adaptive-only models can't take an explicit budget (400) —
                // enable thinking adaptively and let the server manage depth.
                $body['thinking'] = ['type' => 'adaptive'];
                unset($body['temperature']);
            } elseif (ThinkingConfig::modelSupportsThinking($model)) {
                $body['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => (int) $options['thinking_budget_tokens'],
                ];
                unset($body['temperature']);
            }
        }

        // Reasoning-effort dial → Anthropic `output_config.effort` (Fable 5,
        // Opus 4.5+, Sonnet 4.6). Merged after thinking so both can coexist.
        if (isset($options['reasoning_effort']) && is_string($options['reasoning_effort'])) {
            foreach ($this->reasoningEffortFragment($options['reasoning_effort']) as $k => $v) {
                $body[$k] = $v;
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
