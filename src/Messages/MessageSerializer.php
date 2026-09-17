<?php

declare(strict_types=1);

namespace SuperAgent\Messages;

use SuperAgent\Enums\StopReason;

/**
 * Turns a transcript into plain arrays and back.
 *
 * `Message::toArray()` produces the provider-facing shape, which is lossy in
 * one way that matters here: a ToolResultMessage and a UserMessage both come
 * out as `role: user`, so a transcript cannot be rebuilt from it. The encoding
 * below tags each message with the class it came from, which is what lets a
 * resume envelope survive `json_encode()` and come back as the same
 * conversation in a different process.
 *
 * @since 1.2.0
 */
final class MessageSerializer
{
    /** @param Message[] $messages @return list<array<string,mixed>> */
    public static function encodeAll(array $messages): array
    {
        return array_values(array_map([self::class, 'encode'], $messages));
    }

    /** @param list<array<string,mixed>> $encoded @return Message[] */
    public static function decodeAll(array $encoded): array
    {
        return array_values(array_map([self::class, 'decode'], $encoded));
    }

    /** @return array<string,mixed> */
    public static function encode(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            return [
                'kind' => 'assistant',
                'content' => array_map(
                    static fn (ContentBlock $b): array => self::encodeBlock($b),
                    $message->content
                ),
                'stop_reason' => $message->stopReason?->value,
                'metadata' => $message->metadata,
            ];
        }

        if ($message instanceof ToolResultMessage) {
            return [
                'kind' => 'tool_result',
                'content' => array_map(
                    static fn (ContentBlock $b): array => self::encodeBlock($b),
                    $message->content
                ),
            ];
        }

        if ($message instanceof SystemMessage) {
            return ['kind' => 'system', 'content' => $message->content];
        }

        if ($message instanceof UserMessage) {
            return ['kind' => 'user', 'content' => $message->content];
        }

        throw new \InvalidArgumentException(
            'Cannot serialise message of type ' . $message::class . '.'
        );
    }

    /** @param array<string,mixed> $data */
    public static function decode(array $data): Message
    {
        $kind = $data['kind'] ?? null;

        return match ($kind) {
            'assistant' => self::decodeAssistant($data),
            'tool_result' => new ToolResultMessage(
                array_map([self::class, 'decodeBlock'], $data['content'] ?? [])
            ),
            'system' => new SystemMessage((string) ($data['content'] ?? '')),
            'user' => new UserMessage($data['content'] ?? ''),
            default => throw new \InvalidArgumentException(
                'Unknown serialised message kind: ' . var_export($kind, true) . '.'
            ),
        };
    }

    /** @param array<string,mixed> $data */
    private static function decodeAssistant(array $data): AssistantMessage
    {
        $message = new AssistantMessage();
        $message->content = array_map([self::class, 'decodeBlock'], $data['content'] ?? []);
        $message->metadata = $data['metadata'] ?? [];

        $stopReason = $data['stop_reason'] ?? null;
        if (is_string($stopReason)) {
            $message->stopReason = StopReason::tryFrom($stopReason);
        }

        return $message;
    }

    /** @return array<string,mixed> */
    private static function encodeBlock(ContentBlock $block): array
    {
        return [
            'type' => $block->type,
            'text' => $block->text,
            'tool_use_id' => $block->toolUseId,
            'tool_name' => $block->toolName,
            'tool_input' => $block->toolInput,
            'content' => $block->content,
            'is_error' => $block->isError,
            'thinking' => $block->thinking,
        ];
    }

    /** @param array<string,mixed> $data */
    private static function decodeBlock(array $data): ContentBlock
    {
        return new ContentBlock(
            type: (string) ($data['type'] ?? 'text'),
            text: $data['text'] ?? null,
            toolUseId: $data['tool_use_id'] ?? null,
            toolName: $data['tool_name'] ?? null,
            toolInput: $data['tool_input'] ?? null,
            content: $data['content'] ?? null,
            isError: $data['is_error'] ?? null,
            thinking: $data['thinking'] ?? null,
        );
    }
}
