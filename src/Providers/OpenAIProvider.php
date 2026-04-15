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
use SuperAgent\Tools\Tool;

class OpenAIProvider implements LLMProvider
{
    protected Client $client;
    protected string $model;
    protected int $maxTokens;
    protected int $maxRetries;
    protected ?string $organization;

    public function __construct(array $config)
    {
        $authMode = $config['auth_mode'] ?? (isset($config['access_token']) ? 'oauth' : 'api_key');
        $accessToken = $config['access_token'] ?? null;
        $apiKey = $config['api_key'] ?? null;

        $bearer = $authMode === 'oauth' ? $accessToken : $apiKey;
        if (empty($bearer)) {
            throw new ProviderException(
                $authMode === 'oauth' ? 'OAuth access_token is required' : 'API key is required',
                'openai',
            );
        }

        // Trailing slash required so Guzzle treats the request path as relative (RFC 3986).
        // Without it, an absolute path like 'v1/chat/completions' would replace the entire
        // path component of base_uri, silently dropping any custom path prefix.
        $baseUrl = rtrim($config['base_url'] ?? 'https://api.openai.com', '/') . '/';
        $this->organization = $config['organization'] ?? null;
        $this->model = $config['model'] ?? 'gpt-4o';
        $this->maxTokens = $config['max_tokens'] ?? 4096;
        $this->maxRetries = $config['max_retries'] ?? 3;

        $headers = [
            'Authorization' => 'Bearer ' . $bearer,
            'Content-Type' => 'application/json',
        ];

        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }
        if ($authMode === 'oauth' && ! empty($config['account_id'])) {
            $headers['chatgpt-account-id'] = $config['account_id'];
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
                $response = $this->client->post('v1/chat/completions', [
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
                    'openai',
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
                throw new ProviderException($e->getMessage(), 'openai', previous: $e);
            }
        }

        yield from $this->parseSSEStream($response->getBody(), $options['streaming_handler'] ?? null);
    }

    protected function buildRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        // Convert messages to OpenAI format
        $openaiMessages = [];
        
        // Add system prompt if provided
        if ($systemPrompt) {
            $openaiMessages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Convert messages
        foreach ($messages as $message) {
            $openaiMessages[] = $this->convertMessage($message);
        }

        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $openaiMessages,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'stream' => true,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        // Add tools if provided
        if (!empty($tools)) {
            $body['tools'] = $this->convertTools($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        // Add structured output if requested
        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        return $body;
    }

    protected function convertMessage(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            $content = [];
            
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $content[] = [
                        'type' => 'text',
                        'text' => $block->text,
                    ];
                } elseif ($block->type === 'tool_use') {
                    // Convert tool use to OpenAI function call format
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

            return [
                'role' => 'assistant',
                'content' => $content,
            ];
        }

        // For user messages
        return [
            'role' => $message->role,
            'content' => $message->content,
        ];
    }

    protected function convertTools(array $tools): array
    {
        $openaiTools = [];
        
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $openaiTools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name(),
                        'description' => $tool->description(),
                        'parameters' => $tool->inputSchema(),
                    ],
                ];
            }
        }

        return $openaiTools;
    }

    protected function parseSSEStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $buffer = '';
        $messageContent = '';
        $toolCalls = [];
        $usage = null;
        $stopReason = null;

        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (strpos($line, 'data: ') === 0) {
                    $data = substr($line, 6);
                    
                    if ($data === '[DONE]') {
                        break 2;
                    }

                    $json = json_decode($data, true);
                    if (!$json) continue;

                    // Handle different chunk types
                    if (isset($json['choices'][0]['delta'])) {
                        $delta = $json['choices'][0]['delta'];
                        
                        // Text content
                        if (isset($delta['content'])) {
                            $messageContent .= $delta['content'];
                            $handler?->onText($delta['content']);
                        }
                        
                        // Tool calls
                        if (isset($delta['tool_calls'])) {
                            foreach ($delta['tool_calls'] as $toolCall) {
                                if (isset($toolCall['function'])) {
                                    $toolCalls[] = $toolCall;
                                    $handler?->onToolUse(
                                        $toolCall['id'],
                                        $toolCall['function']['name'],
                                        json_decode($toolCall['function']['arguments'], true)
                                    );
                                }
                            }
                        }
                    }

                    // Usage information
                    if (isset($json['usage'])) {
                        $usage = new Usage(
                            $json['usage']['prompt_tokens'] ?? 0,
                            $json['usage']['completion_tokens'] ?? 0
                        );
                    }

                    // Stop reason
                    if (isset($json['choices'][0]['finish_reason'])) {
                        $stopReason = $this->mapStopReason($json['choices'][0]['finish_reason']);
                    }
                }
            }
        }

        // Build final message
        $content = [];
        
        if ($messageContent) {
            $content[] = new ContentBlock('text', $messageContent);
        }
        
        foreach ($toolCalls as $toolCall) {
            $content[] = new ContentBlock(
                'tool_use',
                null,
                $toolCall['id'],
                $toolCall['function']['name'],
                json_decode($toolCall['function']['arguments'], true)
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
        // Check for Retry-After header
        if ($response->hasHeader('Retry-After')) {
            return (float) $response->getHeader('Retry-After')[0];
        }

        // Exponential backoff
        return pow(2, $attempt);
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
        
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getName(): string
    {
        return 'openai';
    }

    /**
     * Get supported models for this provider.
     */
    public function getSupportedModels(): array
    {
        return [
            'gpt-4o',
            'gpt-4o-mini',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo',
            'o1-preview',
            'o1-mini',
        ];
    }

    /**
     * Check if model supports structured output.
     */
    public function supportsStructuredOutput(): bool
    {
        return in_array($this->model, ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo']);
    }

    public function name(): string
    {
        return 'openai';
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
}