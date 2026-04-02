<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Converters;

use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Adapter for OpenAI Responses API format (used by Codex CLI).
 *
 * Converts between the Responses API `input[]` item format and
 * SuperAgent's internal Message objects.
 */
class ResponsesApiAdapter
{
    /**
     * Convert Responses API input items to internal messages.
     *
     * @param array $body The full request body with 'input', 'tools', etc.
     * @return array{messages: Message[], systemPrompt: ?string, tools: array}
     */
    public static function fromResponsesApi(array $body): array
    {
        $messages = [];
        $systemPromptParts = [];
        $input = $body['input'] ?? [];

        // Handle simple string input
        if (is_string($input)) {
            return [
                'messages' => [new UserMessage($input)],
                'systemPrompt' => $body['instructions'] ?? null,
                'tools' => $body['tools'] ?? [],
            ];
        }

        foreach ($input as $item) {
            $type = $item['type'] ?? 'message';

            switch ($type) {
                case 'message':
                    $role = $item['role'] ?? 'user';
                    $content = self::extractContent($item);

                    if ($role === 'system') {
                        $systemPromptParts[] = $content;
                    } elseif ($role === 'user') {
                        $messages[] = new UserMessage($content);
                    }
                    break;

                case 'function_call':
                    $assistant = new AssistantMessage();
                    $assistant->content[] = ContentBlock::toolUse(
                        $item['call_id'] ?? $item['id'] ?? '',
                        $item['name'] ?? '',
                        json_decode($item['arguments'] ?? '{}', true) ?? [],
                    );
                    $assistant->stopReason = StopReason::ToolUse;
                    $messages[] = $assistant;
                    break;

                case 'function_call_output':
                    $messages[] = ToolResultMessage::fromResult(
                        $item['call_id'] ?? '',
                        $item['output'] ?? '',
                    );
                    break;
            }
        }

        // Instructions field acts as system prompt
        $instructions = $body['instructions'] ?? null;
        if ($instructions !== null) {
            array_unshift($systemPromptParts, $instructions);
        }

        return [
            'messages' => $messages,
            'systemPrompt' => ! empty($systemPromptParts) ? implode("\n\n", $systemPromptParts) : null,
            'tools' => $body['tools'] ?? [],
        ];
    }

    /**
     * Convert an AssistantMessage to Responses API output format.
     */
    public static function toResponsesApi(
        AssistantMessage $message,
        string $model,
        string $responseId,
    ): array {
        $output = [];

        foreach ($message->content as $block) {
            if ($block->type === 'text' && $block->text !== null) {
                $output[] = [
                    'type' => 'message',
                    'id' => 'msg_' . bin2hex(random_bytes(8)),
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => $block->text,
                    ]],
                ];
            } elseif ($block->type === 'tool_use') {
                $output[] = [
                    'type' => 'function_call',
                    'id' => 'fc_' . bin2hex(random_bytes(8)),
                    'call_id' => $block->toolUseId,
                    'name' => $block->toolName,
                    'arguments' => json_encode($block->toolInput ?? []),
                ];
            }
        }

        $status = match ($message->stopReason) {
            StopReason::ToolUse => 'incomplete',
            StopReason::MaxTokens => 'incomplete',
            default => 'completed',
        };

        return [
            'id' => $responseId,
            'object' => 'response',
            'created_at' => time(),
            'model' => $model,
            'status' => $status,
            'output' => $output,
            'usage' => [
                'input_tokens' => $message->usage?->inputTokens ?? 0,
                'output_tokens' => $message->usage?->outputTokens ?? 0,
                'total_tokens' => $message->usage?->totalTokens() ?? 0,
            ],
        ];
    }

    /**
     * Generate SSE events for streaming Responses API format.
     *
     * @return string[]
     */
    public static function toStreamEvents(
        AssistantMessage $message,
        string $model,
        string $responseId,
    ): array {
        $events = [];

        // response.created
        $events[] = self::sse('response.created', [
            'response' => [
                'id' => $responseId,
                'object' => 'response',
                'status' => 'in_progress',
                'model' => $model,
                'output' => [],
            ],
        ]);

        $outputIndex = 0;
        foreach ($message->content as $block) {
            if ($block->type === 'text' && $block->text !== null) {
                $itemId = 'msg_' . bin2hex(random_bytes(8));

                // output_item.added
                $events[] = self::sse('response.output_item.added', [
                    'output_index' => $outputIndex,
                    'item' => [
                        'type' => 'message',
                        'id' => $itemId,
                        'role' => 'assistant',
                        'content' => [],
                    ],
                ]);

                // content_part.added + delta
                $events[] = self::sse('response.content_part.delta', [
                    'output_index' => $outputIndex,
                    'content_index' => 0,
                    'delta' => [
                        'type' => 'text_delta',
                        'text' => $block->text,
                    ],
                ]);

                // output_item.done
                $events[] = self::sse('response.output_item.done', [
                    'output_index' => $outputIndex,
                    'item' => [
                        'type' => 'message',
                        'id' => $itemId,
                        'role' => 'assistant',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => $block->text,
                        ]],
                    ],
                ]);

                $outputIndex++;
            } elseif ($block->type === 'tool_use') {
                $fcId = 'fc_' . bin2hex(random_bytes(8));

                $events[] = self::sse('response.output_item.added', [
                    'output_index' => $outputIndex,
                    'item' => [
                        'type' => 'function_call',
                        'id' => $fcId,
                        'call_id' => $block->toolUseId,
                        'name' => $block->toolName,
                        'arguments' => '',
                    ],
                ]);

                $events[] = self::sse('response.function_call_arguments.delta', [
                    'output_index' => $outputIndex,
                    'delta' => json_encode($block->toolInput ?? []),
                ]);

                $events[] = self::sse('response.output_item.done', [
                    'output_index' => $outputIndex,
                    'item' => [
                        'type' => 'function_call',
                        'id' => $fcId,
                        'call_id' => $block->toolUseId,
                        'name' => $block->toolName,
                        'arguments' => json_encode($block->toolInput ?? []),
                    ],
                ]);

                $outputIndex++;
            }
        }

        // response.completed
        $events[] = self::sse('response.completed', [
            'response' => self::toResponsesApi($message, $model, $responseId),
        ]);

        return $events;
    }

    private static function extractContent(array $item): string
    {
        $content = $item['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        // Array of content parts
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (($part['type'] ?? '') === 'input_text') {
                $parts[] = $part['text'] ?? '';
            }
        }

        return implode('', $parts);
    }

    private static function sse(string $event, array $data): string
    {
        return "event: {$event}\ndata: " . json_encode($data) . "\n\n";
    }
}
