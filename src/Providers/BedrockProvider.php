<?php

namespace SuperAgent\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\Credentials;
use Generator;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Enums\StopReason;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;
use SuperAgent\StreamingHandler;
use SuperAgent\Tools\Tool;

class BedrockProvider implements LLMProvider
{
    protected BedrockRuntimeClient $client;
    protected string $model;
    protected int $maxTokens;
    protected int $maxRetries;
    protected string $region;

    public function __construct(array $config)
    {
        $accessKey = $config['access_key'] ?? $config['aws_access_key_id'] ?? 
            throw new ProviderException('AWS access key is required', 'bedrock');
        $secretKey = $config['secret_key'] ?? $config['aws_secret_access_key'] ?? 
            throw new ProviderException('AWS secret key is required', 'bedrock');
        
        $this->region = $config['region'] ?? $config['aws_region'] ?? 'us-east-1';
        $this->model = $config['model'] ?? 'anthropic.claude-3-5-sonnet-20241022-v2:0';
        $this->maxTokens = $config['max_tokens'] ?? 4096;
        $this->maxRetries = $config['max_retries'] ?? 3;

        $credentials = new Credentials($accessKey, $secretKey);

        $this->client = new BedrockRuntimeClient([
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => $credentials,
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
        $modelId = $options['model'] ?? $this->model;
        
        // Different Bedrock models have different input formats
        if (str_contains($modelId, 'anthropic')) {
            yield from $this->chatAnthropic($messages, $tools, $systemPrompt, $options);
        } elseif (str_contains($modelId, 'amazon.titan')) {
            yield from $this->chatTitan($messages, $tools, $systemPrompt, $options);
        } elseif (str_contains($modelId, 'meta.llama')) {
            yield from $this->chatLlama($messages, $tools, $systemPrompt, $options);
        } else {
            throw new ProviderException("Unsupported Bedrock model: {$modelId}", 'bedrock');
        }
    }

    protected function chatAnthropic(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): Generator {
        $body = $this->buildAnthropicRequestBody($messages, $tools, $systemPrompt, $options);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->client->invokeModelWithResponseStream([
                    'modelId' => $options['model'] ?? $this->model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode($body),
                ]);
                break;
            } catch (\Exception $e) {
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((int) (pow(2, $attempt) * 1_000_000));
                    continue;
                }
                throw new ProviderException($e->getMessage(), 'bedrock', previous: $e);
            }
        }

        yield from $this->parseAnthropicStream($response['body'], $options['streaming_handler'] ?? null);
    }

    protected function buildAnthropicRequestBody(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): array {
        // Convert messages to Anthropic format
        $anthropicMessages = [];
        
        foreach ($messages as $message) {
            $anthropicMessages[] = $this->convertMessageToAnthropic($message);
        }

        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'messages' => $anthropicMessages,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        if ($systemPrompt) {
            $body['system'] = $systemPrompt;
        }

        // Add tools if provided
        if (!empty($tools)) {
            $body['tools'] = $this->convertToolsToAnthropic($tools);
            $body['tool_choice'] = $options['tool_choice'] ?? ['type' => 'auto'];
        }

        return $body;
    }

    /**
     * Bedrock's `anthropic.*` models speak Anthropic Messages verbatim
     * (AWS forwards the body unchanged), so encoding lives in the
     * shared `AnthropicEncoder` via the Transcoder facade. Kept as a
     * protected hook so legacy callers reaching it via reflection or
     * subclasses still work.
     *
     * @return array<string, mixed>
     */
    protected function convertMessageToAnthropic(Message $message): array
    {
        return (new \SuperAgent\Conversation\Transcoder())
            ->encode([$message], \SuperAgent\Conversation\WireFamily::Anthropic)[0];
    }

    protected function convertToolsToAnthropic(array $tools): array
    {
        $anthropicTools = [];
        
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $anthropicTools[] = [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'input_schema' => $tool->inputSchema(),
                ];
            }
        }

        return $anthropicTools;
    }

    protected function parseAnthropicStream($stream, ?StreamingHandler $handler = null): Generator
    {
        $messageContent = [];
        $usage = null;
        $stopReason = null;

        foreach ($stream as $event) {
            $chunk = json_decode($event['chunk']['bytes'], true);
            
            if (isset($chunk['type'])) {
                switch ($chunk['type']) {
                    case 'message_start':
                        if (isset($chunk['message']['usage'])) {
                            $usage = new Usage(
                                $chunk['message']['usage']['input_tokens'] ?? 0,
                                $chunk['message']['usage']['output_tokens'] ?? 0
                            );
                        }
                        break;
                        
                    case 'content_block_start':
                        if ($chunk['content_block']['type'] === 'text') {
                            $handler?->onText($chunk['content_block']['text'] ?? '');
                        } elseif ($chunk['content_block']['type'] === 'tool_use') {
                            $handler?->onToolUse(
                                $chunk['content_block']['id'],
                                $chunk['content_block']['name'],
                                []
                            );
                        }
                        break;
                        
                    case 'content_block_delta':
                        if (isset($chunk['delta']['type'])) {
                            if ($chunk['delta']['type'] === 'text_delta') {
                                $text = $chunk['delta']['text'] ?? '';
                                $messageContent[] = ['type' => 'text', 'text' => $text];
                                $handler?->onText($text);
                            } elseif ($chunk['delta']['type'] === 'input_json_delta') {
                                // Handle tool input streaming
                            }
                        }
                        break;
                        
                    case 'message_delta':
                        if (isset($chunk['delta']['stop_reason'])) {
                            $stopReason = $this->mapAnthropicStopReason($chunk['delta']['stop_reason']);
                        }
                        if (isset($chunk['usage'])) {
                            $usage = new Usage(
                                $chunk['usage']['input_tokens'] ?? 0,
                                $chunk['usage']['output_tokens'] ?? 0
                            );
                        }
                        break;
                        
                    case 'message_stop':
                        // Message complete
                        break;
                }
            }
        }

        // Build final message
        $content = [];
        $textContent = '';
        
        foreach ($messageContent as $block) {
            if ($block['type'] === 'text') {
                $textContent .= $block['text'];
            }
        }
        
        if ($textContent) {
            $content[] = new ContentBlock('text', $textContent);
        }

        yield new AssistantMessage(
            content: $content,
            usage: $usage,
            stopReason: $stopReason ?? StopReason::EndTurn,
        );
    }

    protected function chatTitan(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): Generator {
        // Amazon Titan implementation
        $body = [
            'inputText' => $this->messagesToText($messages, $systemPrompt),
            'textGenerationConfig' => [
                'maxTokenCount' => $options['max_tokens'] ?? $this->maxTokens,
                'temperature' => $options['temperature'] ?? 0.7,
                'topP' => $options['top_p'] ?? 0.9,
            ],
        ];

        try {
            $response = $this->client->invokeModel([
                'modelId' => $options['model'] ?? $this->model,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($body),
            ]);
            
            $result = json_decode($response['body'], true);
            
            yield new AssistantMessage(
                content: [new ContentBlock('text', $result['results'][0]['outputText'] ?? '')],
                stopReason: StopReason::EndTurn,
            );
        } catch (\Exception $e) {
            throw new ProviderException($e->getMessage(), 'bedrock', previous: $e);
        }
    }

    protected function chatLlama(
        array $messages,
        array $tools,
        ?string $systemPrompt,
        array $options,
    ): Generator {
        // Meta Llama implementation
        $prompt = $this->messagesToText($messages, $systemPrompt);
        
        $body = [
            'prompt' => $prompt,
            'max_gen_len' => $options['max_tokens'] ?? $this->maxTokens,
            'temperature' => $options['temperature'] ?? 0.7,
            'top_p' => $options['top_p'] ?? 0.9,
        ];

        try {
            $response = $this->client->invokeModel([
                'modelId' => $options['model'] ?? $this->model,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($body),
            ]);
            
            $result = json_decode($response['body'], true);
            
            yield new AssistantMessage(
                content: [new ContentBlock('text', $result['generation'] ?? '')],
                stopReason: StopReason::EndTurn,
            );
        } catch (\Exception $e) {
            throw new ProviderException($e->getMessage(), 'bedrock', previous: $e);
        }
    }

    protected function messagesToText(array $messages, ?string $systemPrompt): string
    {
        $text = '';
        
        if ($systemPrompt) {
            $text .= "System: {$systemPrompt}\n\n";
        }
        
        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                $text .= "Assistant: ";
                foreach ($message->content as $block) {
                    if ($block->type === 'text') {
                        $text .= $block->text;
                    }
                }
                $text .= "\n\n";
            } else {
                $role = ucfirst($message->role);
                $content = is_array($message->content) ? json_encode($message->content) : $message->content;
                $text .= "{$role}: {$content}\n\n";
            }
        }
        
        return $text;
    }

    protected function mapAnthropicStopReason(string $reason): StopReason
    {
        return match ($reason) {
            'end_turn' => StopReason::EndTurn,
            'max_tokens' => StopReason::MaxTokens,
            'stop_sequence' => StopReason::StopSequence,
            'tool_use' => StopReason::ToolUse,
            default => StopReason::EndTurn,
        };
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
        return 'bedrock';
    }

    /**
     * Get supported models for this provider.
     */
    public function getSupportedModels(): array
    {
        return [
            // Anthropic models
            'anthropic.claude-3-5-sonnet-20241022-v2:0',
            'anthropic.claude-3-sonnet-20240229-v1:0',
            'anthropic.claude-3-haiku-20240307-v1:0',
            'anthropic.claude-3-opus-20240229-v1:0',
            
            // Amazon Titan models
            'amazon.titan-text-express-v1',
            'amazon.titan-text-lite-v1',
            
            // Meta Llama models
            'meta.llama3-1-70b-instruct-v1:0',
            'meta.llama3-1-8b-instruct-v1:0',
            
            // Mistral models
            'mistral.mistral-7b-instruct-v0:2',
            'mistral.mixtral-8x7b-instruct-v0:1',
        ];
    }

    public function name(): string
    {
        return 'bedrock';
    }

    public function formatMessages(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $message) {
            $formatted[] = $this->convertMessageToAnthropic($message);
        }
        return $formatted;
    }

    public function formatTools(array $tools): array
    {
        return $this->convertToolsToAnthropic($tools);
    }
}