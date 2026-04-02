<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Streaming;

use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

/**
 * Translates internal AssistantMessage into OpenAI Chat Completions SSE chunks.
 *
 * Produces `data: {...}\n\n` lines compatible with OpenAI's streaming format.
 */
class OpenAIStreamTranslator
{
    private string $requestId;

    private string $model;

    private int $created;

    public function __construct(string $model, ?string $requestId = null)
    {
        $this->model = $model;
        $this->requestId = $requestId ?? ('chatcmpl-' . bin2hex(random_bytes(12)));
        $this->created = time();
    }

    /**
     * Translate an AssistantMessage into an array of SSE data lines.
     *
     * @return string[] Each element is a complete "data: {...}\n\n" string
     */
    public function translate(AssistantMessage $message): array
    {
        $chunks = [];

        // First chunk: role declaration
        $chunks[] = $this->formatChunk([
            'role' => 'assistant',
        ]);

        $toolCallIndex = 0;

        foreach ($message->content as $block) {
            if ($block->type === 'text' && $block->text !== null) {
                // Stream text content - emit as a single chunk
                // (for real streaming, this would be broken into smaller pieces)
                $chunks[] = $this->formatChunk([
                    'content' => $block->text,
                ]);
            } elseif ($block->type === 'tool_use') {
                // Tool call chunk
                $chunks[] = $this->formatChunk([
                    'tool_calls' => [[
                        'index' => $toolCallIndex,
                        'id' => $block->toolUseId,
                        'type' => 'function',
                        'function' => [
                            'name' => $block->toolName,
                            'arguments' => json_encode($block->toolInput ?? []),
                        ],
                    ]],
                ]);
                $toolCallIndex++;
            }
        }

        // Final chunk: finish reason
        $finishReason = match ($message->stopReason) {
            StopReason::EndTurn => 'stop',
            StopReason::ToolUse => 'tool_calls',
            StopReason::MaxTokens => 'length',
            StopReason::StopSequence => 'stop',
            default => 'stop',
        };

        $chunks[] = $this->formatFinishChunk($finishReason, $message);

        // Done sentinel
        $chunks[] = "data: [DONE]\n\n";

        return $chunks;
    }

    /**
     * Format a single delta chunk as SSE data line.
     */
    private function formatChunk(array $delta): string
    {
        $data = [
            'id' => $this->requestId,
            'object' => 'chat.completion.chunk',
            'created' => $this->created,
            'model' => $this->model,
            'choices' => [[
                'index' => 0,
                'delta' => $delta,
                'finish_reason' => null,
            ]],
        ];

        return 'data: ' . json_encode($data) . "\n\n";
    }

    /**
     * Format the final chunk with finish_reason and usage.
     */
    private function formatFinishChunk(string $finishReason, AssistantMessage $message): string
    {
        $data = [
            'id' => $this->requestId,
            'object' => 'chat.completion.chunk',
            'created' => $this->created,
            'model' => $this->model,
            'choices' => [[
                'index' => 0,
                'delta' => (object) [],
                'finish_reason' => $finishReason,
            ]],
        ];

        if ($message->usage) {
            $data['usage'] = [
                'prompt_tokens' => $message->usage->inputTokens,
                'completion_tokens' => $message->usage->outputTokens,
                'total_tokens' => $message->usage->totalTokens(),
            ];
        }

        return 'data: ' . json_encode($data) . "\n\n";
    }
}
