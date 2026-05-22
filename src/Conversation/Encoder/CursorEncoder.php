<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\Message;

/**
 * Family — Cursor (cursor.sh) Composer / Agent endpoints.
 *
 * Cursor's request shape is OpenAI-Chat-Completions-like but with
 * three twists:
 *
 *   1. `role` accepts `user` / `assistant` / `system` (same as OpenAI)
 *      BUT Cursor expects multi-modal content blocks under a `parts[]`
 *      field rather than OpenAI's `content` (string or array). Plain
 *      string content is auto-wrapped as `parts: [{type:'text', text}]`.
 *
 *   2. Tool calls ride on a Cursor-specific `actions[]` array on the
 *      assistant message — NOT OpenAI's `tool_calls` field. Tool
 *      results come back as a `user` turn with `actions[].result`.
 *
 *   3. Cursor injects implicit context blocks (open editor, selection,
 *      cursor position) as a `context[]` field that we deliberately
 *      DO NOT emit — that's the editor's responsibility, not ours.
 *
 * 9Router-borrowed (decolua/9router) — 9Router's adapter matrix
 * supports Cursor as a destination so users behind a Cursor frontend
 * can route to alternate backends. Mirror parity here lets SuperAgent
 * accept inbound Cursor-shaped requests (e.g. via the OpenAI-compat
 * proxy in SuperAICore when the client identifies as Cursor).
 *
 * Tool-pair correlation: Cursor identifies tool calls by `id` (same
 * model as OpenAI Chat Completions). The internal Message id is used
 * verbatim; we don't synthesise new ones.
 */
final class CursorEncoder
{
    /**
     * @param Message[] $messages
     * @return list<array<string,mixed>>
     */
    public function encode(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $entry = $this->encodeOne($message);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    private function encodeOne(Message $message): ?array
    {
        $arr = $message->toArray();
        $role = (string) ($arr['role'] ?? 'user');
        $content = $arr['content'] ?? [];

        if (is_string($content)) {
            return [
                'role'  => $role,
                'parts' => [['type' => 'text', 'text' => $content]],
            ];
        }
        if (!is_array($content)) {
            return null;
        }

        $parts = [];
        $actions = [];
        foreach ($content as $block) {
            if (!is_array($block)) continue;
            $bt = $block['type'] ?? null;
            switch ($bt) {
                case 'text':
                    $parts[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
                    break;
                case 'image':
                    $parts[] = [
                        'type' => 'image',
                        'data' => (string) ($block['source']['data'] ?? $block['data'] ?? ''),
                        'mime' => (string) ($block['source']['media_type'] ?? $block['media_type'] ?? 'image/png'),
                    ];
                    break;
                case 'tool_use':
                    $actions[] = [
                        'id'    => (string) ($block['id'] ?? ''),
                        'name'  => (string) ($block['name'] ?? ''),
                        'input' => $block['input'] ?? [],
                    ];
                    break;
                case 'tool_result':
                    $actions[] = [
                        'id'     => (string) ($block['tool_use_id'] ?? ''),
                        'result' => $block['content'] ?? '',
                        'is_error' => (bool) ($block['is_error'] ?? false),
                    ];
                    break;
                case 'thinking':
                    // Cursor doesn't display thinking blocks — drop them
                    // from the wire (model still received them upstream).
                    break;
            }
        }

        $entry = ['role' => $role];
        if ($parts !== []) $entry['parts'] = $parts;
        if ($actions !== []) $entry['actions'] = $actions;
        return $entry;
    }
}
