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

class OpenRouterProvider implements LLMProvider
{
    protected Client $client;
    protected string $model;
    protected int $maxTokens;
    protected int $maxRetries;
    protected ?string $appName;
    protected ?string $siteUrl;

    public function __construct(array $config)
    {
        $apiKey = $config['api_key'] ?? throw new ProviderException('API key is required', 'openrouter');
        $baseUrl = rtrim($config['base_url'] ?? 'https://openrouter.ai', '/');
        $this->appName = $config['app_name'] ?? 'SuperAgent';
        $this->siteUrl = $config['site_url'] ?? null;
        $this->model = $config['model'] ?? 'anthropic/claude-3-5-sonnet';
        $this->maxTokens = $config['max_tokens'] ?? 4096;
        $this->maxRetries = $config['max_retries'] ?? 3;

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => $this->siteUrl ?? 'https://github.com/superagent',
            'X-Title' => $this->appName,
        ];

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
                $response = $this->client->post('/api/v1/chat/completions', [
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
                    'openrouter',
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
                throw new ProviderException($e->getMessage(), 'openrouter', previous: $e);
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
        // Convert messages to OpenRouter format (OpenAI compatible)
        $openRouterMessages = [];
        
        // Add system prompt if provided
        if ($systemPrompt) {
            $openRouterMessages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Convert messages
        foreach ($messages as $message) {
            $openRouterMessages[] = $this->convertMessage($message);
        }

        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $openRouterMessages,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'stream' => true,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        // Add provider-specific options
        if (isset($options['provider_order'])) {
            $body['provider_order'] = $options['provider_order'];
        }

        if (isset($options['provider_preferences'])) {
            $body['provider_preferences'] = $options['provider_preferences'];
        }

        // Add tools if provided
        if (!empty($tools)) {
            $body['tools'] = $this->convertTools($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        // Add transforms for cost optimization
        if ($options['use_fallbacks'] ?? false) {
            $body['transforms'] = ['fallbacks'];
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
                'content' => count($content) === 1 && $content[0]['type'] === 'text' 
                    ? $content[0]['text'] 
                    : $content,
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
        $openRouterTools = [];
        
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $openRouterTools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name(),
                        'description' => $tool->description(),
                        'parameters' => $tool->inputSchema(),
                    ],
                ];
            }
        }

        return $openRouterTools;
    }

    protected function parseSSEStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $buffer = '';
        $messageContent = '';
        $toolCalls = [];
        $usage = null;
        $stopReason = null;
        $modelUsed = null;

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

                    // Track which model was actually used
                    if (isset($json['model'])) {
                        $modelUsed = $json['model'];
                    }

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

        $message = new AssistantMessage(
            content: $content,
            usage: $usage,
            stopReason: $stopReason ?? StopReason::EndTurn,
        );

        // Add metadata about which model was used
        if ($modelUsed) {
            $message->metadata['model_used'] = $modelUsed;
        }

        yield $message;
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
        return 'openrouter';
    }

    /**
     * Get available models from OpenRouter.
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->client->get('/api/v1/models');
            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(fn($model) => [
                'id' => $model['id'],
                'name' => $model['name'] ?? $model['id'],
                'context_length' => $model['context_length'] ?? null,
                'pricing' => $model['pricing'] ?? null,
            ], $data['data'] ?? []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get supported models for this provider.
     */
    public function getSupportedModels(): array
    {
        return [
            // Anthropic models
            'anthropic/claude-3-5-sonnet',
            'anthropic/claude-3-opus',
            'anthropic/claude-3-sonnet',
            'anthropic/claude-3-haiku',
            
            // OpenAI models
            'openai/gpt-4o',
            'openai/gpt-4-turbo',
            'openai/gpt-3.5-turbo',
            
            // Google models
            'google/gemini-pro',
            'google/gemini-pro-1.5',
            
            // Meta models
            'meta-llama/llama-3-70b-instruct',
            'meta-llama/llama-3-8b-instruct',
            
            // Mistral models
            'mistralai/mistral-large',
            'mistralai/mixtral-8x7b-instruct',
        ];
    }

    public function name(): string
    {
        return 'openrouter';
    }

    public function formatMessages(array $messages): array
    {
        $formatted = [];
        if (!empty($this->systemPrompt)) {
            $formatted[] = ['role' => 'system', 'content' => $this->systemPrompt];
        }
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