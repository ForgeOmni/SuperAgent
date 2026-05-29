<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Canonical streaming event taxonomy across LLM providers.
 *
 * Different providers ship different streaming event shapes:
 *   - Anthropic: content_block_start, content_block_delta (text / thinking /
 *                tool_use), content_block_stop, message_delta, message_stop
 *   - OpenAI Chat Completions: delta {content, tool_calls[]}
 *   - OpenAI Responses API: response.output_text.delta, response.tool_calls.delta,
 *                            response.reasoning.delta
 *   - Gemini: candidates[].content.parts[] with role / functionCall / executableCode
 *   - Gemini 3.5: + groundingMetadata.searchEntryPoint, thoughts (when thinking on)
 *
 * Adopters should NORMALIZE provider-native events into these types when
 * forwarding to higher layers (CLI UI, ACP session/update notifications,
 * SuperAICore /processes SSE, Pi event stream JSONL). This is the
 * unified abstraction Pi promises via `pi-ai` (pi.dev/docs/latest/sdk —
 * "Subscribe to events for real-time updates").
 *
 * Each event carries a uniform envelope:
 *
 *   ['type' => self::TEXT_DELTA, 'index' => 0, 'text' => 'Hello']
 *   ['type' => self::THINKING_DELTA, 'text' => 'Let me think...']
 *   ['type' => self::TOOL_CALL_DELTA, 'id' => 'call_1', 'name' => 'edit', 'arguments_delta' => '{"p']
 *   ['type' => self::TOOL_CALL_COMPLETE, 'id' => 'call_1', 'arguments' => [...]]
 *   ['type' => self::GROUNDING_CITATION, 'sources' => [{title, uri, snippet}]]
 *   ['type' => self::USAGE, 'input_tokens' => N, 'output_tokens' => M]
 *   ['type' => self::STOP, 'reason' => 'end_turn']
 */
final class StreamEventTypes
{
    public const TEXT_START      = 'text_start';
    public const TEXT_DELTA      = 'text_delta';
    public const TEXT_STOP       = 'text_stop';

    public const THINKING_START  = 'thinking_start';
    public const THINKING_DELTA  = 'thinking_delta';
    public const THINKING_STOP   = 'thinking_stop';

    public const TOOL_CALL_START    = 'tool_call_start';
    public const TOOL_CALL_DELTA    = 'tool_call_delta';
    public const TOOL_CALL_COMPLETE = 'tool_call_complete';

    public const GROUNDING_CITATION = 'grounding_citation';

    public const USAGE = 'usage';
    public const STOP  = 'stop';
    public const ERROR = 'error';

    public const ALL = [
        self::TEXT_START, self::TEXT_DELTA, self::TEXT_STOP,
        self::THINKING_START, self::THINKING_DELTA, self::THINKING_STOP,
        self::TOOL_CALL_START, self::TOOL_CALL_DELTA, self::TOOL_CALL_COMPLETE,
        self::GROUNDING_CITATION,
        self::USAGE, self::STOP, self::ERROR,
    ];

    /**
     * Helper: build a uniform envelope. Always sets `type` and never
     * silently drops caller-supplied fields.
     */
    public static function event(string $type, array $fields = []): array
    {
        $fields['type'] = $type;
        return $fields;
    }
}
