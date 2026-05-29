<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

/**
 * Translate provider-native streaming events into the canonical
 * {@see StreamEventTypes} taxonomy.
 *
 * Adapter pattern: each provider's streaming loop calls the matching
 * `fromAnthropic` / `fromOpenAi` / `fromOpenAiResponses` / `fromGemini`
 * helper on every raw event and yields the normalized envelope. Higher
 * layers (UI, JSONL, ACP) only ever see {@see StreamEventTypes::ALL}
 * types — they don't care which provider produced them.
 *
 * Implementations are intentionally permissive (unknown event shapes
 * return null rather than throwing) because provider SSE schemas drift
 * across minor versions and we'd rather degrade to "no event emitted"
 * than crash mid-stream.
 */
final class StreamEventTranslator
{
    /**
     * Anthropic SSE → canonical. Handles the v2023-06-01 + v2024-* shapes:
     * `content_block_start` / `content_block_delta` / `content_block_stop`
     * with delta.type ∈ {text_delta, thinking_delta, input_json_delta}.
     *
     * @return array<string,mixed>|null
     */
    public static function fromAnthropic(array $raw): ?array
    {
        $eventType = $raw['type'] ?? null;
        switch ($eventType) {
            case 'content_block_start':
                $block = $raw['content_block'] ?? [];
                $bt = $block['type'] ?? null;
                if ($bt === 'text') {
                    return StreamEventTypes::event(StreamEventTypes::TEXT_START, ['index' => $raw['index'] ?? 0]);
                }
                if ($bt === 'thinking') {
                    return StreamEventTypes::event(StreamEventTypes::THINKING_START, ['index' => $raw['index'] ?? 0]);
                }
                if ($bt === 'tool_use') {
                    return StreamEventTypes::event(StreamEventTypes::TOOL_CALL_START, [
                        'id' => $block['id'] ?? '',
                        'name' => $block['name'] ?? '',
                        'index' => $raw['index'] ?? 0,
                    ]);
                }
                return null;
            case 'content_block_delta':
                $delta = $raw['delta'] ?? [];
                $dt = $delta['type'] ?? null;
                if ($dt === 'text_delta') {
                    return StreamEventTypes::event(StreamEventTypes::TEXT_DELTA, [
                        'index' => $raw['index'] ?? 0,
                        'text' => (string) ($delta['text'] ?? ''),
                    ]);
                }
                if ($dt === 'thinking_delta') {
                    return StreamEventTypes::event(StreamEventTypes::THINKING_DELTA, [
                        'text' => (string) ($delta['thinking'] ?? ''),
                    ]);
                }
                if ($dt === 'input_json_delta') {
                    return StreamEventTypes::event(StreamEventTypes::TOOL_CALL_DELTA, [
                        'index' => $raw['index'] ?? 0,
                        'arguments_delta' => (string) ($delta['partial_json'] ?? ''),
                    ]);
                }
                return null;
            case 'message_delta':
                if (isset($raw['usage'])) {
                    return StreamEventTypes::event(StreamEventTypes::USAGE, [
                        'input_tokens' => (int) ($raw['usage']['input_tokens'] ?? 0),
                        'output_tokens' => (int) ($raw['usage']['output_tokens'] ?? 0),
                    ]);
                }
                return null;
            case 'message_stop':
                return StreamEventTypes::event(StreamEventTypes::STOP, [
                    'reason' => $raw['stop_reason'] ?? 'end_turn',
                ]);
        }
        return null;
    }

    /**
     * OpenAI Chat Completions SSE → canonical.
     * `chunk.choices[0].delta = {content?: string, tool_calls?: [...]}`
     */
    public static function fromOpenAi(array $chunk): ?array
    {
        $choice = $chunk['choices'][0] ?? null;
        if ($choice === null) {
            if (isset($chunk['usage'])) {
                return StreamEventTypes::event(StreamEventTypes::USAGE, [
                    'input_tokens' => (int) ($chunk['usage']['prompt_tokens'] ?? 0),
                    'output_tokens' => (int) ($chunk['usage']['completion_tokens'] ?? 0),
                ]);
            }
            return null;
        }
        $delta = $choice['delta'] ?? [];

        if (isset($delta['content']) && $delta['content'] !== null) {
            return StreamEventTypes::event(StreamEventTypes::TEXT_DELTA, [
                'index' => 0,
                'text' => (string) $delta['content'],
            ]);
        }
        if (!empty($delta['tool_calls'])) {
            $first = $delta['tool_calls'][0];
            return StreamEventTypes::event(StreamEventTypes::TOOL_CALL_DELTA, [
                'index' => (int) ($first['index'] ?? 0),
                'id' => $first['id'] ?? '',
                'name' => $first['function']['name'] ?? '',
                'arguments_delta' => (string) ($first['function']['arguments'] ?? ''),
            ]);
        }
        if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
            return StreamEventTypes::event(StreamEventTypes::STOP, [
                'reason' => (string) $choice['finish_reason'],
            ]);
        }
        return null;
    }

    /**
     * Gemini stream → canonical.
     * `candidate.content.parts[]` with: text / functionCall / inlineData.
     * Gemini 3.5+: also `thoughts` (when ThinkingConfig enabled) and
     * `groundingMetadata.searchEntryPoint` (when grounding=true).
     */
    public static function fromGemini(array $chunk): ?array
    {
        $candidate = $chunk['candidates'][0] ?? null;
        if ($candidate === null) {
            if (isset($chunk['usageMetadata'])) {
                return StreamEventTypes::event(StreamEventTypes::USAGE, [
                    'input_tokens' => (int) ($chunk['usageMetadata']['promptTokenCount'] ?? 0),
                    'output_tokens' => (int) ($chunk['usageMetadata']['candidatesTokenCount'] ?? 0),
                ]);
            }
            return null;
        }

        $parts = $candidate['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['text']) && !empty($part['text'])) {
                $isThought = !empty($part['thought']);
                return StreamEventTypes::event(
                    $isThought ? StreamEventTypes::THINKING_DELTA : StreamEventTypes::TEXT_DELTA,
                    ['text' => (string) $part['text']]
                );
            }
            if (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                return StreamEventTypes::event(StreamEventTypes::TOOL_CALL_COMPLETE, [
                    'id' => $fc['name'] ?? '',
                    'name' => $fc['name'] ?? '',
                    'arguments' => $fc['args'] ?? [],
                ]);
            }
        }

        $grounding = $candidate['groundingMetadata'] ?? null;
        if ($grounding !== null && !empty($grounding['groundingChunks'])) {
            return StreamEventTypes::event(StreamEventTypes::GROUNDING_CITATION, [
                'sources' => array_map(
                    fn($c) => [
                        'title' => $c['web']['title'] ?? null,
                        'uri'   => $c['web']['uri'] ?? null,
                    ],
                    $grounding['groundingChunks']
                ),
            ]);
        }

        if (isset($candidate['finishReason'])) {
            return StreamEventTypes::event(StreamEventTypes::STOP, [
                'reason' => (string) $candidate['finishReason'],
            ]);
        }
        return null;
    }
}
