<?php

declare(strict_types=1);

namespace SuperAgent\Tracing;

/**
 * Pi-aligned session event stream.
 *
 * Canonical event taxonomy borrowed from pi's JSON Event Stream Mode
 * (pi.dev/docs/latest/json) so SuperAgent sessions can be replayed by any
 * pi-compatible viewer (and so external tools like SuperAICore's `/processes`
 * page emit a consistent vocabulary regardless of which backend ran the turn).
 *
 * This class is a low-level emitter. Call sites push events via the static
 * helpers; an attached writer (PiEventStreamWriter, or a custom listener)
 * persists or forwards them.
 */
final class PiEventStream
{
    public const SESSION = 'session';

    public const AGENT_START = 'agent_start';
    public const AGENT_END   = 'agent_end';

    public const TURN_START = 'turn_start';
    public const TURN_END   = 'turn_end';

    public const MESSAGE_START  = 'message_start';
    public const MESSAGE_UPDATE = 'message_update';
    public const MESSAGE_END    = 'message_end';

    public const TOOL_EXECUTION_START  = 'tool_execution_start';
    public const TOOL_EXECUTION_UPDATE = 'tool_execution_update';
    public const TOOL_EXECUTION_END    = 'tool_execution_end';

    public const QUEUE_UPDATE = 'queue_update';

    public const COMPACTION_START = 'compaction_start';
    public const COMPACTION_END   = 'compaction_end';

    public const AUTO_RETRY_START = 'auto_retry_start';
    public const AUTO_RETRY_END   = 'auto_retry_end';

    public const MODEL_CHANGE          = 'model_change';
    public const THINKING_LEVEL_CHANGE = 'thinking_level_change';

    public const ALL = [
        self::SESSION,
        self::AGENT_START, self::AGENT_END,
        self::TURN_START, self::TURN_END,
        self::MESSAGE_START, self::MESSAGE_UPDATE, self::MESSAGE_END,
        self::TOOL_EXECUTION_START, self::TOOL_EXECUTION_UPDATE, self::TOOL_EXECUTION_END,
        self::QUEUE_UPDATE,
        self::COMPACTION_START, self::COMPACTION_END,
        self::AUTO_RETRY_START, self::AUTO_RETRY_END,
        self::MODEL_CHANGE, self::THINKING_LEVEL_CHANGE,
    ];

    /**
     * Legacy → Pi name map. Translate SuperAgent's existing event names
     * (from StructuredLogger / SimpleTracingManager) into the Pi taxonomy
     * when forwarding to pi-compatible consumers. Keep the legacy names in
     * StructuredLogger for back-compat in this minor; remove in next major.
     */
    public const LEGACY_ALIAS = [
        'tool_execution' => self::TOOL_EXECUTION_END,
        'tool_input'     => self::TOOL_EXECUTION_START,
        'tool_result'    => self::TOOL_EXECUTION_END,
        'llm_request'    => self::TURN_START,
        'llm_response'   => self::TURN_END,
        'llm_message'    => self::MESSAGE_UPDATE,
        'api_query'      => self::TURN_START,
    ];

    /** @var array<int, callable(array): void> */
    private static array $listeners = [];

    public static function subscribe(callable $listener): int
    {
        self::$listeners[] = $listener;
        return array_key_last(self::$listeners);
    }

    public static function unsubscribe(int $handle): void
    {
        unset(self::$listeners[$handle]);
    }

    public static function reset(): void
    {
        self::$listeners = [];
    }

    /**
     * Emit a Pi-aligned event. `$type` must be one of the constants above
     * (assert via `in_array($type, PiEventStream::ALL, true)` in dev).
     *
     * Common fields the caller should supply:
     * - sessionId, turnId, messageId, toolCallId, parentId
     * - timestamp (RFC3339; auto-filled when missing)
     * - usage, model, role, deltas, ...
     */
    public static function emit(string $type, array $payload = []): void
    {
        $payload['type'] = $type;
        $payload['timestamp'] = $payload['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z');

        foreach (self::$listeners as $listener) {
            $listener($payload);
        }
    }

    public static function translateLegacy(string $legacyType): ?string
    {
        return self::LEGACY_ALIAS[$legacyType] ?? null;
    }
}
