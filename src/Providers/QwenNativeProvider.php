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
use SuperAgent\Providers\Capabilities\SupportsCodeInterpreter;
use SuperAgent\Providers\Capabilities\SupportsThinking;
use SuperAgent\Providers\Features\FeatureDispatcher;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;
use SuperAgent\StreamingHandler;
use SuperAgent\Tools\Tool;

/**
 * Alibaba Qwen — DashScope **native** API (legacy / opt-in).
 *
 * @deprecated Prefer the default `QwenProvider`, which speaks the
 *             OpenAI-compatible endpoint `/compatible-mode/v1/chat/completions`
 *             that Alibaba's own qwen-code CLI uses exclusively. This class
 *             is kept for backwards compatibility with code that depends on
 *             the native body shape (e.g. `parameters.thinking_budget`,
 *             which is NOT exposed on the OpenAI-compatible endpoint).
 *
 *             Opt-in via `ProviderRegistry::create('qwen-native', $config)`
 *             or by configuring `provider: qwen-native` explicitly. New
 *             code should target `qwen` (the default — chat-completions path).
 *
 * Chat lives at `POST /api/v1/services/aigc/text-generation/generation`.
 * The body shape is vendor-specific and does NOT follow `/chat/completions`:
 *
 *     {
 *       "model": "qwen3.6-max-preview",
 *       "input":  { "messages": [...] },
 *       "parameters": {
 *         "result_format": "message",
 *         "incremental_output": true,
 *         "enable_thinking": true|false,
 *         "thinking_budget": 4000,
 *         "enable_code_interpreter": true|false,
 *         "tools": [...],
 *         "tool_choice": "auto"|"none"|{...},
 *         "parallel_tool_calls": true
 *       }
 *     }
 *
 * Streaming is opt-in via the `X-DashScope-SSE: enable` request header plus
 * `parameters.incremental_output = true`; the SSE frame shape matches
 * OpenAI's (`data: {...}\n\n`, `[DONE]`) closely enough to reuse one parser.
 *
 * Regions (key-host-bound):
 *   - `intl` (default) → dashscope-intl.aliyuncs.com — Singapore
 *   - `us`             → dashscope-us.aliyuncs.com    — Virginia
 *   - `cn`             → dashscope.aliyuncs.com       — Beijing
 *   - `hk`             → cn-hongkong.dashscope.aliyuncs.com
 */
class QwenNativeProvider implements LLMProvider, SupportsThinking, SupportsCodeInterpreter
{
    public function thinkingRequestFragment(int $budgetTokens): array
    {
        // DashScope native puts thinking knobs inside the `parameters` sub-object,
        // and (unlike the OpenAI-compatible endpoint) honours an explicit budget.
        return ['parameters' => [
            'enable_thinking' => true,
            'thinking_budget' => max(1, $budgetTokens),
        ]];
    }

    public function codeInterpreterRequestFragment(array $options = []): array
    {
        // DashScope's code interpreter activator lives under `parameters`
        // alongside thinking knobs; FeatureAdapter::merge() deep-merges
        // this into existing `parameters` without clobbering them.
        return ['parameters' => [
            'enable_code_interpreter' => true,
        ]];
    }


    protected Client $client;
    protected string $model;
    protected int $maxTokens;
    protected int $maxRetries;
    protected string $region;
    protected string $apiKey;

    public function __construct(array $config)
    {
        $apiKey = $config['api_key'] ?? null;
        if (empty($apiKey)) {
            throw new ProviderException('API key is required', 'qwen');
        }
        $this->apiKey = $apiKey;

        $this->region = $config['region'] ?? 'intl';
        $this->model = $config['model'] ?? 'qwen3.6-max-preview';
        $this->maxTokens = (int) ($config['max_tokens'] ?? 8192);
        $this->maxRetries = (int) ($config['max_retries'] ?? 3);

        $baseUrl = rtrim(
            $config['base_url'] ?? $this->regionToBaseUrl($this->region),
            '/',
        ) . '/';

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => (int) ($config['timeout'] ?? 300),
        ]);
    }

    protected function regionToBaseUrl(string $region): string
    {
        return match ($region) {
            'intl' => 'https://dashscope-intl.aliyuncs.com',
            'us' => 'https://dashscope-us.aliyuncs.com',
            'cn' => 'https://dashscope.aliyuncs.com',
            'hk' => 'https://cn-hongkong.dashscope.aliyuncs.com',
            default => throw new ProviderException(
                "Unknown region '{$region}' for qwen (expected: intl, us, cn, hk)",
                'qwen',
            ),
        };
    }

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
                $response = $this->client->post(
                    'api/v1/services/aigc/text-generation/generation',
                    [
                        'json' => $body,
                        'headers' => ['X-DashScope-SSE' => 'enable'],
                        'stream' => true,
                    ],
                );
                break;
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
                    continue;
                }

                throw new ProviderException(
                    $responseBody['message'] ?? $responseBody['error']['message'] ?? $e->getMessage(),
                    'qwen',
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
                throw new ProviderException($e->getMessage(), 'qwen', previous: $e);
            }
        }

        yield from $this->parseSSEStream(
            $response->getBody(),
            $options['streaming_handler'] ?? null,
        );
    }

    protected function buildRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        $dsMessages = [];

        if ($systemPrompt) {
            $dsMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Body assembly funnels through the Transcoder so the wire
        // shape stays in lockstep with formatMessages().
        foreach ($this->formatMessages($messages) as $wire) {
            $dsMessages[] = $wire;
        }

        $parameters = [
            'result_format' => 'message',
            'incremental_output' => true,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->maxTokens),
            'temperature' => (float) ($options['temperature'] ?? 0.7),
        ];

        if (! empty($tools)) {
            $parameters['tools'] = $this->convertTools($tools);
            $parameters['tool_choice'] = $options['tool_choice'] ?? 'auto';
            if (isset($options['parallel_tool_calls'])) {
                $parameters['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
            }
        }

        if (! empty($options['enable_thinking'])) {
            $parameters['enable_thinking'] = true;
            if (isset($options['thinking_budget'])) {
                $parameters['thinking_budget'] = (int) $options['thinking_budget'];
            }
        }

        if (! empty($options['enable_code_interpreter'])) {
            $parameters['enable_code_interpreter'] = true;
        }

        $body = [
            'model' => $options['model'] ?? $this->model,
            'input' => ['messages' => $dsMessages],
            'parameters' => $parameters,
        ];

        // Generic feature dispatch (thinking / caching / web_search / …).
        // No-op when `$options['features']` is absent — Compat-safe.
        FeatureDispatcher::apply($this, $options, $body);

        return $body;
    }

    /**
     * Convert one internal Message into ZERO OR MORE DashScope wire
     * messages. DashScope's "result_format=message" branch follows the
     * OpenAI Chat Completions shape almost verbatim — assistant emits
     * tool_calls[]; tool results come back as separate role:tool entries
     * with tool_call_id correlation.
     *
     * Returns a list to allow ToolResultMessage (which carries N parallel
     * results) to expand into N wire messages.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function convertMessage(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            $textParts = [];
            $toolCalls = [];
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $textParts[] = (string) ($block->text ?? '');
                } elseif ($block->type === 'tool_use') {
                    $toolCalls[] = [
                        'id'   => (string) ($block->toolUseId ?? ''),
                        'type' => 'function',
                        'function' => [
                            'name'      => (string) ($block->toolName ?? ''),
                            'arguments' => json_encode(
                                empty($block->toolInput) ? new \stdClass() : $block->toolInput,
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            ),
                        ],
                    ];
                }
                // thinking blocks: dropped — DashScope's reasoning output
                // is one-way (server → client); there's no wire field for
                // feeding signed reasoning back in.
            }
            $out = ['role' => 'assistant'];
            $text = implode('', $textParts);
            if ($text !== '') {
                $out['content'] = $text;
            } elseif ($toolCalls !== []) {
                $out['content'] = '';
            } else {
                $out['content'] = '';
            }
            if ($toolCalls !== []) {
                $out['tool_calls'] = $toolCalls;
            }
            return [$out];
        }

        if ($message instanceof ToolResultMessage) {
            $out = [];
            foreach ($message->content as $block) {
                if ($block->type !== 'tool_result') {
                    continue;
                }
                $out[] = [
                    'role'         => 'tool',
                    'tool_call_id' => (string) ($block->toolUseId ?? ''),
                    'content'      => (string) ($block->content ?? ''),
                ];
            }
            return $out;
        }

        if ($message instanceof SystemMessage) {
            return [['role' => 'system', 'content' => $message->content]];
        }

        if ($message instanceof UserMessage) {
            return [['role' => 'user', 'content' => $message->content]];
        }

        return [['role' => $message->role->value, 'content' => '']];
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

                if (strpos($line, 'data:') !== 0) {
                    continue;
                }
                // DashScope uses `data:` without a space; OpenAI uses `data: `.
                $data = ltrim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $json = json_decode($data, true);
                if (! $json) {
                    continue;
                }

                $output = $json['output'] ?? [];
                $choices = $output['choices'] ?? [];
                if (! empty($choices[0]['message'])) {
                    $msg = $choices[0]['message'];
                    if (isset($msg['content']) && $msg['content'] !== '') {
                        $messageContent .= $msg['content'];
                        $handler?->onText($msg['content']);
                    }
                    if (! empty($msg['tool_calls'])) {
                        foreach ($msg['tool_calls'] as $toolCall) {
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

                if (isset($choices[0]['finish_reason']) && $choices[0]['finish_reason'] !== 'null') {
                    $stopReason = $this->mapStopReason($choices[0]['finish_reason']);
                }

                if (isset($json['usage'])) {
                    $usage = new Usage(
                        $json['usage']['input_tokens'] ?? 0,
                        $json['usage']['output_tokens'] ?? 0,
                    );
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
            'tool_calls' => StopReason::ToolUse,
            default => StopReason::EndTurn,
        };
    }

    public function formatMessages(array $messages): array
    {
        return (new \SuperAgent\Conversation\Transcoder())
            ->encode($messages, \SuperAgent\Conversation\WireFamily::DashScope);
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
        // Reports as 'qwen' so existing observability / cost-attribution
        // pipelines that key off provider name keep working when callers
        // opt into this legacy class via `qwen-native`.
        return 'qwen';
    }

    public function getRegion(): string
    {
        return $this->region;
    }
}
