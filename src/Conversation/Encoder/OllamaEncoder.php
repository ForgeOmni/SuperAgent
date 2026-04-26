<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\SystemMessage;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Family F — Ollama (`/api/chat`).
 *
 * Ollama's wire shape is OpenAI-Chat-shaped at the top level
 * (`role: system|user|assistant|tool`, optional `tool_calls[]` on
 * assistant, separate `role:tool` entries for results) — but its
 * tool-calling support varies by underlying model and only landed
 * across the board in mid-2024. To stay compatible with the entire
 * range of locally-served models, the encoder takes a conservative
 * stance:
 *
 *   - Text and tool_calls are emitted on assistant turns when present,
 *     in the OpenAI-compatible shape Ollama accepts.
 *   - Tool results emit as `role:tool` with `tool_call_id` — Ollama
 *     accepts this shape on tool-capable models and ignores it on the
 *     ones that can't tool-call (the worst case is the model
 *     hallucinating around an unfamiliar conversation turn, not a
 *     wire-format rejection).
 *   - Bare-string content for user/system turns is preserved as a
 *     string. Array content is JSON-encoded into a single string so
 *     non-multimodal Ollama deployments don't choke on a structured
 *     content payload they can't parse.
 *   - Thinking blocks are dropped — Ollama has no representation for
 *     signed reasoning.
 *
 * The previous hand-rolled converter on `OllamaProvider` did not
 * emit tool_calls or `role:tool` at all, so any prior agent loop
 * that ran tools against Ollama lost the tool history on the next
 * turn. Centralizing here means Ollama gains the same fix the rest
 * of the family-B providers got in P0.
 */
class OllamaEncoder
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
            return [['role' => 'user', 'content' => $this->stringifyUserContent($message->content)]];
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
                        'arguments' => empty($block->toolInput) ? new \stdClass() : $block->toolInput,
                    ],
                ];
                continue;
            }
            // thinking blocks: dropped.
        }

        $out = [
            'role'    => 'assistant',
            'content' => implode('', $textParts),
        ];
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

    /**
     * @param string|array<int|string, mixed> $content
     */
    private function stringifyUserContent(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        return (string) json_encode($content, JSON_UNESCAPED_UNICODE);
    }
}
