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
 * Bidirectional adapter between OpenAI message format and SuperAgent internal format.
 *
 * Inbound:  OpenAI array → Message objects (for enhancers that need internal types)
 * Outbound: Message objects → OpenAI array (for returning enhanced messages to OpenAI)
 */
class OpenAIMessageAdapter
{
    /**
     * Convert an array of OpenAI-format messages to internal Message objects.
     *
     * Also extracts and returns the system prompt separately.
     *
     * @return array{messages: Message[], systemPrompt: ?string}
     */
    public static function fromOpenAI(array $openaiMessages): array
    {
        $messages = [];
        $systemPromptParts = [];
        $pendingToolResults = [];

        foreach ($openaiMessages as $msg) {
            $role = $msg['role'] ?? '';

            switch ($role) {
                case 'system':
                    $systemPromptParts[] = is_string($msg['content']) ? $msg['content'] : json_encode($msg['content']);
                    break;

                case 'user':
                    // Flush any pending tool results before user message
                    if (! empty($pendingToolResults)) {
                        $messages[] = ToolResultMessage::fromResults($pendingToolResults);
                        $pendingToolResults = [];
                    }
                    $messages[] = new UserMessage($msg['content'] ?? '');
                    break;

                case 'assistant':
                    // Flush pending tool results
                    if (! empty($pendingToolResults)) {
                        $messages[] = ToolResultMessage::fromResults($pendingToolResults);
                        $pendingToolResults = [];
                    }

                    $assistant = new AssistantMessage();

                    // Text content
                    $textContent = $msg['content'] ?? null;
                    if (is_string($textContent) && $textContent !== '') {
                        $assistant->content[] = ContentBlock::text($textContent);
                    } elseif (is_array($textContent)) {
                        foreach ($textContent as $part) {
                            if (($part['type'] ?? '') === 'text') {
                                $assistant->content[] = ContentBlock::text($part['text'] ?? '');
                            }
                        }
                    }

                    // Tool calls
                    if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                        foreach ($msg['tool_calls'] as $toolCall) {
                            $fn = $toolCall['function'] ?? [];
                            $assistant->content[] = ContentBlock::toolUse(
                                $toolCall['id'] ?? '',
                                $fn['name'] ?? '',
                                json_decode($fn['arguments'] ?? '{}', true) ?? [],
                            );
                        }
                        $assistant->stopReason = StopReason::ToolUse;
                    }

                    $messages[] = $assistant;
                    break;

                case 'tool':
                    // Accumulate tool results; will be flushed as a single ToolResultMessage
                    $pendingToolResults[] = [
                        'tool_use_id' => $msg['tool_call_id'] ?? '',
                        'content' => is_string($msg['content'] ?? null) ? $msg['content'] : json_encode($msg['content']),
                        'is_error' => false,
                    ];
                    break;
            }
        }

        // Flush remaining tool results
        if (! empty($pendingToolResults)) {
            $messages[] = ToolResultMessage::fromResults($pendingToolResults);
        }

        return [
            'messages' => $messages,
            'systemPrompt' => ! empty($systemPromptParts) ? implode("\n\n", $systemPromptParts) : null,
        ];
    }

    /**
     * Convert internal Message objects back to OpenAI-format arrays.
     *
     * @param Message[] $messages
     * @param string|null $systemPrompt If provided, prepended as a system message
     */
    public static function toOpenAI(array $messages, ?string $systemPrompt = null): array
    {
        $openaiMessages = [];

        if ($systemPrompt !== null) {
            $openaiMessages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $openaiMessages[] = [
                    'role' => 'user',
                    'content' => $message->content,
                ];
            } elseif ($message instanceof AssistantMessage) {
                $openaiMessages[] = self::assistantToOpenAI($message);
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->content as $block) {
                    $openaiMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $block->toolUseId,
                        'content' => $block->content ?? '',
                    ];
                }
            }
        }

        return $openaiMessages;
    }

    /**
     * Convert an AssistantMessage to OpenAI format.
     */
    public static function assistantToOpenAI(AssistantMessage $message): array
    {
        $toolCalls = [];
        $textParts = [];

        foreach ($message->content as $block) {
            if ($block->type === 'text' && $block->text !== null) {
                $textParts[] = $block->text;
            } elseif ($block->type === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block->toolUseId,
                    'type' => 'function',
                    'function' => [
                        'name' => $block->toolName,
                        'arguments' => json_encode($block->toolInput ?? []),
                    ],
                ];
            }
        }

        $result = ['role' => 'assistant'];

        if (! empty($toolCalls)) {
            $result['content'] = ! empty($textParts) ? implode('', $textParts) : null;
            $result['tool_calls'] = $toolCalls;
        } else {
            $result['content'] = implode('', $textParts);
        }

        return $result;
    }

    /**
     * Convert an AssistantMessage to OpenAI Chat Completion response format.
     */
    public static function toCompletionResponse(
        AssistantMessage $message,
        string $model,
        string $requestId,
    ): array {
        $choice = self::assistantToOpenAI($message);
        $choice['finish_reason'] = match ($message->stopReason) {
            StopReason::EndTurn => 'stop',
            StopReason::ToolUse => 'tool_calls',
            StopReason::MaxTokens => 'length',
            StopReason::StopSequence => 'stop',
            default => 'stop',
        };

        return [
            'id' => 'chatcmpl-' . $requestId,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                array_merge(['index' => 0, 'message' => [
                    'role' => $choice['role'],
                    'content' => $choice['content'] ?? null,
                    ...isset($choice['tool_calls']) ? ['tool_calls' => $choice['tool_calls']] : [],
                ]], ['finish_reason' => $choice['finish_reason']]),
            ],
            'usage' => [
                'prompt_tokens' => $message->usage?->inputTokens ?? 0,
                'completion_tokens' => $message->usage?->outputTokens ?? 0,
                'total_tokens' => $message->usage?->totalTokens() ?? 0,
            ],
        ];
    }
}
