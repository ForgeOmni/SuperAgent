<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;

/**
 * Family C — OpenAI Responses API (`/v1/responses`).
 *
 * The Responses API is the most structurally divergent of the six
 * families. Each conversation turn doesn't become one wire message —
 * it becomes one or more `input[]` items, each with its own `type`:
 *
 *   - `message`              — a user/assistant/system turn carrying
 *                              `input_text` or `output_text` parts
 *   - `function_call`        — assistant's tool invocation
 *   - `function_call_output` — the tool's result (one per call_id)
 *   - `reasoning`            — server-side encrypted reasoning items
 *
 * Tool-call correlation uses `call_id` (the encoder reuses the
 * internal `tool_use_id` verbatim; the Responses API accepts arbitrary
 * stable strings the same way Chat Completions does).
 *
 * Cross-family handoff implications worth noting (the encoder itself
 * doesn't enforce these — they belong to HandoffPolicy in P4 — but
 * the encoder must NOT assume them away):
 *
 *   - `previous_response_id` continuation is provider-scoped state, not
 *     a wire field on the message. When a conversation is handed off
 *     INTO the Responses API from another family, the caller MUST
 *     reset `lastResponseId` so the full history rides on this
 *     request. When it is handed OUT, the cached id is meaningless to
 *     the new provider and gets discarded.
 *   - Encrypted `reasoning` items emitted by the Responses API are
 *     ALSO provider-scoped — they decrypt only against the same
 *     conversation under the same model, and don't even survive a
 *     model swap inside the same family. The internal Message model
 *     deliberately doesn't carry them today; if a future change
 *     introduces a `reasoning` ContentBlock type, the encoder above
 *     and the OpenAIChatEncoder both must explicitly drop it on
 *     cross-family encode.
 *
 * The encoder is a verbatim port of the previous
 * `OpenAIResponsesProvider::convertMessagesToInput()`. Centralizing
 * here means the same canonical translation is reachable from the
 * Transcoder and any future cross-family handoff path.
 */
class OpenAIResponsesEncoder
{
    /**
     * @param Message[] $messages
     * @return list<array<string, mixed>>
     */
    public function encode(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $roleValue = $m->role->value;

            if ($m instanceof AssistantMessage) {
                foreach ($m->content as $block) {
                    if ($block->type === 'text' && $block->text !== null && $block->text !== '') {
                        $out[] = [
                            'type'    => 'message',
                            'role'    => 'assistant',
                            'content' => [['type' => 'output_text', 'text' => $block->text]],
                        ];
                        continue;
                    }
                    if ($block->type === 'tool_use') {
                        $out[] = [
                            'type'      => 'function_call',
                            'call_id'   => (string) ($block->toolUseId ?? ''),
                            'name'      => (string) ($block->toolName ?? ''),
                            'arguments' => (string) json_encode(
                                $block->toolInput ?? new \stdClass()
                            ),
                        ];
                        continue;
                    }
                    // thinking / vendor-only blocks: dropped.
                }
                continue;
            }

            if ($m instanceof ToolResultMessage) {
                foreach ($m->content as $block) {
                    if ($block->type !== 'tool_result') {
                        continue;
                    }
                    $callId = (string) ($block->toolUseId ?? '');
                    $contentVal = $block->content ?? '';
                    $output = is_array($contentVal)
                        ? (string) json_encode($contentVal)
                        : (string) $contentVal;
                    $out[] = [
                        'type'    => 'function_call_output',
                        'call_id' => $callId,
                        'output'  => $output,
                    ];
                }
                continue;
            }

            // Plain user / system turns. Content can be a bare string,
            // an array of strings, an array of {type:text,text:...}
            // dicts (legacy assembly path), or an array of ContentBlock
            // text instances. Everything else gets dropped to keep the
            // wire shape strictly typed.
            $content = property_exists($m, 'content') ? $m->content : null;

            $parts = [];
            if (is_string($content)) {
                if ($content !== '') {
                    $parts[] = ['type' => 'input_text', 'text' => $content];
                }
            } elseif (is_array($content)) {
                foreach ($content as $c) {
                    if (is_string($c)) {
                        if ($c !== '') {
                            $parts[] = ['type' => 'input_text', 'text' => $c];
                        }
                    } elseif (is_array($c) && ($c['type'] ?? null) === 'text') {
                        $parts[] = [
                            'type' => 'input_text',
                            'text' => (string) ($c['text'] ?? ''),
                        ];
                    } elseif ($c instanceof ContentBlock
                        && $c->type === 'text'
                        && $c->text !== null) {
                        $parts[] = ['type' => 'input_text', 'text' => $c->text];
                    }
                }
            }
            if ($parts === []) {
                continue;
            }

            $out[] = [
                'type'    => 'message',
                'role'    => $roleValue === 'system' ? 'system' : 'user',
                'content' => $parts,
            ];
        }
        return $out;
    }
}
