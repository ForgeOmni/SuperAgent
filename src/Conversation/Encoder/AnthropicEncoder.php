<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Encoder;

use SuperAgent\Messages\Message;

/**
 * Family A — Anthropic Messages.
 *
 * The internal `Message` / `ContentBlock` shape was built to be a 1:1
 * mirror of Anthropic's wire format (the same `text` / `tool_use` /
 * `tool_result` / `thinking` block types, the same `role:user`
 * carrying parallel tool_results, the same `system` separation handled
 * one layer up). Encoding is therefore a literal `toArray()` per
 * message — no mapping, no plurality changes, no id rewrites.
 *
 * Bedrock's `anthropic.*` model invocations are the same wire — AWS
 * forwards the body verbatim — so BedrockProvider routes through this
 * encoder too. Before this class existed, Bedrock had a hand-rolled
 * copy of the Anthropic encoder that drifted from the canonical one
 * (read `id`/`name`/`input` properties that don't exist on
 * ContentBlock, dropped `ToolResultMessage`, leaked the Role enum into
 * the wire). Centralizing here means there is exactly one place to fix
 * Anthropic-shaped encoding bugs.
 */
class AnthropicEncoder
{
    /**
     * @param Message[] $messages
     * @return list<array<string, mixed>>
     */
    public function encode(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = $message->toArray();
        }
        return $out;
    }
}
