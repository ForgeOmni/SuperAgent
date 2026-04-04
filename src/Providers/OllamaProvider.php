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

class OllamaProvider implements LLMProvider
{
    protected Client $client;
    protected string $model;
    protected int $maxTokens;
    protected int $maxRetries;
    protected float $temperature;
    protected int $contextLength;
    protected bool $keepAlive;

    public function __construct(array $config)
    {
        // Trailing slash required so Guzzle treats the request path as relative (RFC 3986).
        // Without it, absolute paths like 'api/chat' would replace the entire path component
        // of base_uri, silently dropping any custom path prefix.
        $baseUrl = rtrim($config['base_url'] ?? 'http://localhost:11434', '/') . '/';
        $this->model = $config['model'] ?? 'llama2';
        $this->maxTokens = $config['max_tokens'] ?? 2048;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->temperature = $config['temperature'] ?? 0.7;
        $this->contextLength = $config['context_length'] ?? 4096;
        $this->keepAlive = $config['keep_alive'] ?? true;

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => $config['timeout'] ?? 300,
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
        // Check if model is available
        if (!$this->isModelAvailable($options['model'] ?? $this->model)) {
            throw new ProviderException(
                "Model '{$this->model}' is not available. Please pull it first with: ollama pull {$this->model}",
                'ollama'
            );
        }

        // Ollama doesn't support tool calls natively yet
        if (!empty($tools)) {
            yield from $this->chatWithToolEmulation($messages, $tools, $systemPrompt, $options);
            return;
        }

        $body = $this->buildRequestBody($messages, $systemPrompt, $options);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->client->post('api/chat', [
                    'json' => $body,
                    'stream' => true,
                ]);
                break;
            } catch (ClientException $e) {
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
                    continue;
                }
                
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                throw new ProviderException(
                    $responseBody['error'] ?? $e->getMessage(),
                    'ollama',
                    $e->getResponse()->getStatusCode(),
                    $responseBody,
                    $e,
                );
            } catch (GuzzleException $e) {
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
                    continue;
                }
                throw new ProviderException($e->getMessage(), 'ollama', previous: $e);
            }
        }

        yield from $this->parseJsonStream($response->getBody(), $options['streaming_handler'] ?? null);
    }

    protected function buildRequestBody(
        array $messages,
        ?string $systemPrompt,
        array $options,
    ): array {
        // Convert messages to Ollama format
        $ollamaMessages = [];
        
        // Add system prompt if provided
        if ($systemPrompt) {
            $ollamaMessages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Convert messages
        foreach ($messages as $message) {
            $ollamaMessages[] = $this->convertMessage($message);
        }

        $body = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $ollamaMessages,
            'stream' => true,
            'options' => [
                'temperature' => $options['temperature'] ?? $this->temperature,
                'num_predict' => $options['max_tokens'] ?? $this->maxTokens,
                'num_ctx' => $options['context_length'] ?? $this->contextLength,
            ],
        ];

        if (isset($options['format'])) {
            $body['format'] = $options['format'];
        }

        if ($this->keepAlive) {
            $body['keep_alive'] = '5m';
        }

        return $body;
    }

    protected function convertMessage(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            $text = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }

            return [
                'role' => 'assistant',
                'content' => $text,
            ];
        }

        // For user messages
        $content = is_array($message->content) 
            ? json_encode($message->content) 
            : $message->content;
        
        return [
            'role' => $message->role,
            'content' => $content,
        ];
    }

    protected function parseJsonStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $messageContent = '';
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $done = false;

        while (!$stream->eof()) {
            $line = $stream->read(8192);
            if (empty($line)) continue;

            // Ollama sends newline-delimited JSON
            $chunks = explode("\n", $line);
            
            foreach ($chunks as $chunk) {
                if (empty(trim($chunk))) continue;

                $json = json_decode($chunk, true);
                if (!$json) continue;

                // Handle message content
                if (isset($json['message']['content'])) {
                    $content = $json['message']['content'];
                    $messageContent .= $content;
                    $handler?->onText($content);
                }

                // Track token usage
                if (isset($json['prompt_eval_count'])) {
                    $totalPromptTokens = $json['prompt_eval_count'];
                }
                if (isset($json['eval_count'])) {
                    $totalCompletionTokens = $json['eval_count'];
                }

                // Check if done
                if (isset($json['done']) && $json['done']) {
                    $done = true;
                    break;
                }
            }

            if ($done) break;
        }

        $usage = new Usage($totalPromptTokens, $totalCompletionTokens);

        yield new AssistantMessage(
            content: [new ContentBlock('text', $messageContent)],
            usage: $usage,
            stopReason: StopReason::EndTurn,
        );
    }

    /**
     * Emulate tool calling for Ollama models that don't support it natively.
     */
    protected function chatWithToolEmulation(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): Generator {
        // Build a prompt that includes tool definitions
        $toolPrompt = $this->buildToolPrompt($tools);
        $enhancedSystemPrompt = $systemPrompt 
            ? "{$systemPrompt}\n\n{$toolPrompt}"
            : $toolPrompt;

        // First, get the model's response with tool instructions
        $body = $this->buildRequestBody($messages, $enhancedSystemPrompt, $options);
        $body['format'] = 'json';

        $attempt = 0;
        while (true) {
            try {
                $response = $this->client->post('api/chat', [
                    'json' => $body,
                    'stream' => true,
                ]);
                break;
            } catch (GuzzleException $e) {
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
                    continue;
                }
                throw new ProviderException($e->getMessage(), 'ollama', previous: $e);
            }
        }

        // Parse response and look for tool calls
        yield from $this->parseToolResponse($response->getBody(), $tools, $options['streaming_handler'] ?? null);
    }

    protected function buildToolPrompt(array $tools): string
    {
        $prompt = "You have access to the following tools:\n\n";
        
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $prompt .= "- {$tool->name()}: {$tool->description()}\n";
                $prompt .= "  Parameters: " . json_encode($tool->inputSchema()) . "\n\n";
            }
        }

        $prompt .= "When you need to use a tool, respond with JSON in this format:\n";
        $prompt .= '{"tool": "tool_name", "parameters": {...}}' . "\n\n";
        $prompt .= "If you don't need to use a tool, respond with JSON:\n";
        $prompt .= '{"response": "your text response"}' . "\n";

        return $prompt;
    }

    protected function parseToolResponse($stream, array $tools, ?StreamingHandler $handler = null): Generator
    {
        $fullResponse = '';
        
        while (!$stream->eof()) {
            $line = $stream->read(8192);
            if (empty($line)) continue;

            $chunks = explode("\n", $line);
            foreach ($chunks as $chunk) {
                if (empty(trim($chunk))) continue;
                
                $json = json_decode($chunk, true);
                if (!$json) continue;
                
                if (isset($json['message']['content'])) {
                    $fullResponse .= $json['message']['content'];
                }
                
                if (isset($json['done']) && $json['done']) {
                    break 2;
                }
            }
        }

        // Try to parse the response as JSON
        $parsed = json_decode($fullResponse, true);
        
        if ($parsed && isset($parsed['tool'])) {
            // Model wants to use a tool
            $content = [
                new ContentBlock(
                    'tool_use',
                    null,
                    uniqid('tool_'),
                    $parsed['tool'],
                    $parsed['parameters'] ?? []
                )
            ];
            
            yield new AssistantMessage(
                content: $content,
                stopReason: StopReason::ToolUse,
            );
        } else {
            // Regular text response
            $text = $parsed['response'] ?? $fullResponse;
            $handler?->onText($text);
            
            yield new AssistantMessage(
                content: [new ContentBlock('text', $text)],
                stopReason: StopReason::EndTurn,
            );
        }
    }

    /**
     * Check if a model is available locally.
     */
    protected function isModelAvailable(string $model): bool
    {
        try {
            $response = $this->client->get('/api/tags');
            $data = json_decode($response->getBody()->getContents(), true);
            
            foreach ($data['models'] ?? [] as $availableModel) {
                if ($availableModel['name'] === $model) {
                    return true;
                }
            }
            
            return false;
        } catch (\Exception $e) {
            // If we can't check, assume it's available
            return true;
        }
    }

    /**
     * Pull a model from the Ollama registry.
     */
    public function pullModel(string $model): void
    {
        try {
            $this->client->post('api/pull', [
                'json' => ['name' => $model],
            ]);
        } catch (\Exception $e) {
            throw new ProviderException(
                "Failed to pull model '{$model}': " . $e->getMessage(),
                'ollama',
                previous: $e
            );
        }
    }

    /**
     * List available local models.
     */
    public function listModels(): array
    {
        try {
            $response = $this->client->get('/api/tags');
            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(fn($model) => [
                'name' => $model['name'],
                'size' => $model['size'] ?? null,
                'modified' => $model['modified_at'] ?? null,
            ], $data['models'] ?? []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Generate embeddings using Ollama.
     */
    public function embed(string $text, ?string $model = null): array
    {
        try {
            $response = $this->client->post('api/embeddings', [
                'json' => [
                    'model' => $model ?? $this->model,
                    'prompt' => $text,
                ],
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['embedding'] ?? [];
        } catch (\Exception $e) {
            throw new ProviderException(
                "Failed to generate embeddings: " . $e->getMessage(),
                'ollama',
                previous: $e
            );
        }
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
        return 'ollama';
    }

    /**
     * Get supported models for this provider.
     */
    public function getSupportedModels(): array
    {
        return [
            // Popular open models
            'llama2',
            'llama2:7b',
            'llama2:13b',
            'llama2:70b',
            'llama3',
            'llama3:8b',
            'llama3:70b',
            'mistral',
            'mixtral',
            'codellama',
            'deepseek-coder',
            'phi',
            'orca-mini',
            'vicuna',
            'wizard-vicuna-uncensored',
            'neural-chat',
            'starling-lm',
            'nous-hermes2',
            'openhermes',
            'zephyr',
            'qwen',
            'yi',
        ];
    }

    public function name(): string
    {
        return 'ollama';
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
        // Ollama doesn't have native tool support yet
        return [];
    }
}