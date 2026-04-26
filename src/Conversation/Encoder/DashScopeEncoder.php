<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Family E — Alibaba DashScope native (`text-generation/generation`)
 * with `result_format=message`.
 *
 * Wire shape is OpenAI-Chat-Completions-shaped almost verbatim —
 * assistant emits `tool_calls[]`, tool results come back as separate
 * `role:tool` entries with `tool_call_id` correlation. The two
 * material differences from family B that justify a dedicated encoder
 * rather than aliasing OpenAIChatEncoder:
 *
 *   - DashScope's `enable_thinking` / `thinking_budget` reasoning
 *     output is one-way (server → client). There is no wire field to
 *     feed signed reasoning back in, so the encoder drops thinking
 *     blocks the same way OpenAIChatEncoder does — but the dropped
 *     content gets re-derived from `AssistantMessage::$metadata` if
 *     the conversation is later handed back to a Qwen-Native target
 *     (P5 wires that path).
 *   - Empty assistant content alongside tool_calls is emitted as the
 *     empty string, not null. DashScope's earlier API revisions
 *     reject `content: null` outright; using `""` is the wire-safe
 *     superset that every revision accepts.
 */
class DashScopeEncoder
{
    /**
     * @param Message[] $messages
     * @return list<array<string, mixed>>
     */
    public function encode(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            foreach ($this->encodeOne($message) as $wire) {
                $out[] = $wire;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function encodeOne(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            return [$this->encodeAssistant($message)];
        }
        if ($message instanceof ToolResultMessage) {
            return $this->encodeToolResults($message);
        }
        if ($message instanceof SystemMessage) {
            return [['role' => 'system', 'content' => $message->content]];
        }
        if ($message instanceof UserMessage) {
            return [['role' => 'user', 'content' => $message->content]];
        }
        return [['role' => $message->role->value, 'content' => '']];
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeAssistant(AssistantMessage $message): array
    {
        $textParts = [];
        $toolCalls = [];

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $textParts[] = (string) ($block->text ?? '');
                continue;
            }
            if ($block->type === 'tool_use') {
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
                continue;
            }
            // thinking blocks: dropped on outbound — see class docblock.
        }

        $out = ['role' => 'assistant'];
        $text = implode('', $textParts);
        // DashScope rejects content:null, so we always emit a string
        // (empty when only tool_calls are present).
        $out['content'] = $text;
        if ($toolCalls !== []) {
            $out['tool_calls'] = $toolCalls;
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function encodeToolResults(ToolResultMessage $message): array
    {
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
}
