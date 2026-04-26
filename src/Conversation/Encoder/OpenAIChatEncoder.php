<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Family B — OpenAI Chat Completions wire format.
 *
 * Used by OpenAI proper and every "OpenAI-compatible" backend: Kimi,
 * GLM, MiniMax, Qwen (proxied), OpenRouter, LMStudio. The wire shape
 * is messages[] with `role: system|user|assistant|tool`, parallel tool
 * calls riding inside one assistant message's `tool_calls[]`, and N
 * separate `role:tool` messages (one per tool result).
 *
 * The conversion plurality matters: a single internal
 * ToolResultMessage carrying N parallel tool_result blocks MUST become
 * N wire messages. The original hand-rolled converter in
 * ChatCompletionsProvider got this wrong (early-returned on the first
 * tool_use; missed ToolResultMessage entirely; read non-existent
 * properties off ContentBlock); centralizing here means the right
 * shape lives in exactly one place.
 *
 * Lossy by design — Anthropic-only artifacts that Chat Completions has
 * no representation for are dropped on encode:
 *
 *   - thinking blocks (no signed-reasoning channel in Chat Completions)
 *   - cache_control hints (Anthropic-only prompt-cache markers)
 *   - vendor-specific content blocks (image input encodings, etc.)
 *
 * The dropped artifacts must NOT be discarded from the internal
 * representation — they live on AssistantMessage::$metadata so a later
 * round-trip back to a native provider can recover them. Re-attaching
 * those artifacts is the encoder's caller's responsibility, not this
 * class's.
 */
class OpenAIChatEncoder
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
        // Defensive fallback for unknown Message subclasses — emit a
        // legal wire shape rather than letting a Role enum object leak
        // through to the wire.
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
                        // Empty input → `{}` (object), not `[]` (array).
                        // OpenAI's parser tolerates both, but some
                        // compatible backends (older GLM, niche local
                        // servers) reject the array form.
                        'arguments' => json_encode(
                            empty($block->toolInput) ? new \stdClass() : $block->toolInput,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ),
                    ],
                ];
                continue;
            }
            // thinking / vendor-only blocks: intentionally dropped.
        }

        $out = ['role' => 'assistant'];
        $text = implode('', $textParts);
        if ($text !== '') {
            $out['content'] = $text;
        } else {
            // No text → content:null when we have tool calls (the
            // canonical OpenAI shape), or empty string for the
            // degenerate "assistant with nothing" case.
            $out['content'] = $toolCalls === [] ? '' : null;
        }
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
