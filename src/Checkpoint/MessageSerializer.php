<?php

declare(strict_types=1);

namespace SuperAgent\Checkpoint;

use SuperAgent\Enums\StopReason;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\Usage;
use SuperAgent\Messages\UserMessage;

/**
 * Serializes and deserializes Message objects for checkpoint persistence.
 *
 * All Message subclasses have toArray() but lack fromArray(). This class
 * provides the missing deserialization path.
 */
class MessageSerializer
{
    /**
     * Serialize a message to an array.
     */
    public static function serialize(Message $message): array
    {
        if ($message instanceof AssistantMessage) {
            return self::serializeAssistant($message);
        }

        $data = $message->toArray();
        $data['_class'] = match (true) {
            $message instanceof ToolResultMessage => 'tool_result',
            $message instanceof UserMessage => 'user',
            default => 'unknown',
        };

        return $data;
    }

    /**
     * Serialize AssistantMessage with all fields (toArray() omits stop_reason/usage/metadata).
     */
    private static function serializeAssistant(AssistantMessage $message): array
    {
        $data = [
            '_class' => 'assistant',
            'role' => 'assistant',
            'content' => array_map(fn (ContentBlock $b) => $b->toArray(), $message->content),
            'stop_reason' => $message->stopReason?->value,
            'metadata' => $message->metadata,
        ];

        if ($message->usage !== null) {
            $data['usage'] = $message->usage->toArray();
        }

        return $data;
    }

    /**
     * Deserialize a message from an array.
     */
    public static function deserialize(array $data): Message
    {
        $class = $data['_class'] ?? 'unknown';

        return match ($class) {
            'assistant' => self::deserializeAssistant($data),
            'tool_result' => self::deserializeToolResult($data),
            'user' => self::deserializeUser($data),
            default => throw new \InvalidArgumentException("Unknown message class: {$class}"),
        };
    }

    /**
     * Serialize an array of messages.
     *
     * @param Message[] $messages
     */
    public static function serializeAll(array $messages): array
    {
        return array_map([self::class, 'serialize'], $messages);
    }

    /**
     * Deserialize an array of messages.
     *
     * @return Message[]
     */
    public static function deserializeAll(array $data): array
    {
        return array_map([self::class, 'deserialize'], $data);
    }

    private static function deserializeAssistant(array $data): AssistantMessage
    {
        $msg = new AssistantMessage();

        foreach ($data['content'] ?? [] as $blockData) {
            $msg->content[] = self::deserializeContentBlock($blockData);
        }

        if (isset($data['usage'])) {
            $msg->usage = new Usage(
                inputTokens: $data['usage']['input_tokens'] ?? 0,
                outputTokens: $data['usage']['output_tokens'] ?? 0,
                cacheCreationInputTokens: $data['usage']['cache_creation_input_tokens'] ?? null,
                cacheReadInputTokens: $data['usage']['cache_read_input_tokens'] ?? null,
            );
        }

        if (isset($data['stop_reason'])) {
            $msg->stopReason = StopReason::tryFrom($data['stop_reason']);
        }

        $msg->metadata = $data['metadata'] ?? [];

        return $msg;
    }

    private static function deserializeToolResult(array $data): ToolResultMessage
    {
        $results = [];
        foreach ($data['content'] ?? [] as $item) {
            $results[] = [
                'tool_use_id' => $item['tool_use_id'] ?? $item['id'] ?? '',
                'content' => $item['content'] ?? '',
                'is_error' => $item['is_error'] ?? false,
            ];
        }

        return ToolResultMessage::fromResults($results);
    }

    private static function deserializeUser(array $data): UserMessage
    {
        return new UserMessage($data['content'] ?? '');
    }

    private static function deserializeContentBlock(array $data): ContentBlock
    {
        $type = $data['type'] ?? 'text';

        return match ($type) {
            'text' => ContentBlock::text($data['text'] ?? ''),
            'tool_use' => ContentBlock::toolUse(
                $data['id'] ?? $data['tool_use_id'] ?? '',
                $data['name'] ?? $data['tool_name'] ?? '',
                $data['input'] ?? $data['tool_input'] ?? [],
            ),
            'tool_result' => ContentBlock::toolResult(
                $data['tool_use_id'] ?? '',
                $data['content'] ?? '',
                $data['is_error'] ?? false,
            ),
            'thinking' => ContentBlock::thinking($data['thinking'] ?? ''),
            default => ContentBlock::text($data['text'] ?? ''),
        };
    }
}
