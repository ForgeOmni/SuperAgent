<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;

/**
 * Family D — Google Gemini `generateContent` / `streamGenerateContent`.
 *
 * Gemini's wire shape diverges from every other family in three ways
 * the encoder has to compensate for:
 *
 *   1. Roles are `user` / `model` — there is no `assistant`.
 *   2. There are no tool-call IDs. A tool result (`functionResponse`)
 *      correlates to its call (`functionCall`) by `name` (and the
 *      ordering of parts within the request). The internal Message
 *      representation always carries an id, so we resolve id → name
 *      via a one-pass scan of the history before encoding the
 *      `functionResponse` parts. Gemini's stream parser already
 *      synthesises stable internal ids of the form `gemini_<hex>_<n>`
 *      so a Gemini-originated conversation handed off to (say)
 *      Anthropic and then back to Gemini round-trips correctly without
 *      any external mapping table — the (id, name) pairs travel with
 *      the AssistantMessage content blocks.
 *   3. `functionResponse.response` MUST be a JSON object; bare strings
 *      are wrapped as `{"content": "<string>"}`. Errors get an
 *      additional `error: true` flag so the model can distinguish
 *      failure modes.
 *
 * System prompts do NOT live in `contents[]` — they go on the request
 * body's top-level `systemInstruction` field. The encoder therefore
 * silently skips `SystemMessage` entries; callers wire system prompts
 * separately in `buildRequestBody()`.
 *
 * The encoder also tolerates `UserMessage` content arrays that hold
 * inline `tool_result` shapes (some legacy callers assemble them
 * directly without an intervening `ToolResultMessage`) — those get
 * converted into `functionResponse` parts on a `user` turn just like
 * a proper `ToolResultMessage` would.
 */
class GeminiEncoder
{
    /**
     * @param Message[] $messages
     * @return list<array<string, mixed>>
     */
    public function encode(array $messages): array
    {
        $toolNames = $this->buildToolNameIndex($messages);

        $contents = [];
        foreach ($messages as $message) {
            $entry = $this->encodeOne($message, $toolNames);
            if ($entry !== null) {
                $contents[] = $entry;
            }
        }
        return $contents;
    }

    /**
     * Walk the history once to collect toolUseId → toolName so
     * functionResponse parts can resolve their `name` field. The map
     * is per-encode and rebuilt deterministically from the message
     * list, so it round-trips through any persistence layer the
     * caller chooses.
     *
     * @param Message[] $messages
     * @return array<string, string>
     */
    private function buildToolNameIndex(array $messages): array
    {
        $toolNames = [];
        foreach ($messages as $m) {
            if (! $m instanceof AssistantMessage) {
                continue;
            }
            foreach ($m->content as $block) {
                if ($block->type === 'tool_use'
                    && $block->toolUseId !== null && $block->toolUseId !== ''
                    && $block->toolName !== null && $block->toolName !== ''
                ) {
                    $toolNames[$block->toolUseId] = $block->toolName;
                }
            }
        }
        return $toolNames;
    }

    /**
     * @param array<string, string> $toolNames
     * @return array<string, mixed>|null  null = skip (empty / system)
     */
    private function encodeOne(Message $message, array $toolNames): ?array
    {
        if ($message instanceof AssistantMessage) {
            return $this->encodeAssistant($message);
        }
        if ($message instanceof ToolResultMessage) {
            return $this->encodeToolResults($message, $toolNames);
        }
        // SystemMessage is wired into the request body's
        // systemInstruction field by the provider, not the contents[]
        // array — drop it here.
        if ($message->role->value === 'system') {
            return null;
        }
        // Plain user message — content is string|array; the array form
        // can carry inline tool_result entries (legacy assembly path).
        $rawContent = $message->toArray()['content'] ?? '';
        $parts = $this->encodePlainUserContent($rawContent, $toolNames);
        if ($parts === []) {
            return null;
        }
        return ['role' => 'user', 'parts' => $parts];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function encodeAssistant(AssistantMessage $message): ?array
    {
        $parts = [];
        foreach ($message->content as $block) {
            if ($block->type === 'text' && $block->text !== null && $block->text !== '') {
                $parts[] = ['text' => $block->text];
                continue;
            }
            if ($block->type === 'tool_use') {
                $parts[] = [
                    'functionCall' => [
                        'name' => (string) ($block->toolName ?? ''),
                        'args' => $block->toolInput ?? (object) [],
                    ],
                ];
                continue;
            }
            // thinking / vendor-only blocks: dropped.
        }
        if ($parts === []) {
            return null;
        }
        return ['role' => 'model', 'parts' => $parts];
    }

    /**
     * @param array<string, string> $toolNames
     * @return array<string, mixed>|null
     */
    private function encodeToolResults(ToolResultMessage $message, array $toolNames): ?array
    {
        $parts = [];
        foreach ($message->content as $block) {
            if ($block->type !== 'tool_result') {
                continue;
            }
            $name = $toolNames[$block->toolUseId ?? ''] ?? '';
            $parts[] = [
                'functionResponse' => [
                    'name'     => $name,
                    'response' => $this->wrapFunctionResponse(
                        $block->content,
                        (bool) $block->isError
                    ),
                ],
            ];
        }
        if ($parts === []) {
            return null;
        }
        return ['role' => 'user', 'parts' => $parts];
    }

    /**
     * @param string|array<int|string, mixed> $content
     * @param array<string, string> $toolNames
     * @return list<array<string, mixed>>
     */
    private function encodePlainUserContent(string|array $content, array $toolNames): array
    {
        if (is_string($content)) {
            return $content === '' ? [] : [['text' => $content]];
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                if ($item !== '') {
                    $parts[] = ['text' => $item];
                }
                continue;
            }
            if ($item instanceof ContentBlock) {
                if ($item->type === 'text' && $item->text !== null && $item->text !== '') {
                    $parts[] = ['text' => $item->text];
                } elseif ($item->type === 'tool_result') {
                    $parts[] = [
                        'functionResponse' => [
                            'name'     => $toolNames[$item->toolUseId ?? ''] ?? '',
                            'response' => $this->wrapFunctionResponse(
                                $item->content,
                                (bool) $item->isError
                            ),
                        ],
                    ];
                }
                continue;
            }
            if (! is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? null;
            if ($type === 'text') {
                $text = (string) ($item['text'] ?? '');
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }
            } elseif ($type === 'tool_result') {
                $id = (string) ($item['tool_use_id'] ?? '');
                $parts[] = [
                    'functionResponse' => [
                        'name'     => $toolNames[$id] ?? '',
                        'response' => $this->wrapFunctionResponse(
                            $item['content'] ?? '',
                            (bool) ($item['is_error'] ?? false),
                        ),
                    ],
                ];
            }
        }
        return $parts;
    }

    /**
     * Gemini requires `functionResponse.response` to be a JSON object.
     * If the tool emitted a string that already parses as a JSON
     * object, pass it through verbatim; otherwise wrap under a
     * `content` key. Errors get a top-level `error: true` so the model
     * can distinguish failures from successful empty responses.
     *
     * @return array<string, mixed>
     */
    private function wrapFunctionResponse(?string $content, bool $isError): array
    {
        $payload = $content ?? '';
        $decoded = json_decode($payload, true);
        $body = is_array($decoded) ? $decoded : ['content' => $payload];

        if ($isError) {
            $body['error'] = true;
        }
        return $body;
    }
}
