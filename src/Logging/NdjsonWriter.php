<?php

declare(strict_types=1);

namespace SuperAgent\Logging;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;

/**
 * Writes Claude Code-compatible NDJSON (Newline Delimited JSON) events.
 *
 * Each line is a self-contained JSON object matching CC's stream-json format.
 * The process monitor (bridge/sessionRunner) can parse these to extract
 * real-time tool activity, text output, and execution results.
 *
 * Event types emitted:
 *   - "assistant"  — LLM response with text and/or tool_use content blocks
 *   - "user"       — tool_result messages (parent_tool_use_id set)
 *   - "result"     — final execution result (success or error subtypes)
 *
 * @see https://github.com/anthropics/claude-code (bridge/sessionRunner.ts)
 */
class NdjsonWriter
{
    /** @var resource|null File handle or STDERR */
    private $stream;

    private string $sessionId;
    private int $startTimeMs;

    public function __construct(
        private readonly string $agentId,
        ?string $sessionId = null,
        /** @var resource|null $stream  Writable stream (defaults to STDERR) */
        $stream = null,
    ) {
        $this->stream = $stream ?? STDERR;
        $this->sessionId = $sessionId ?? $agentId;
        $this->startTimeMs = (int) (microtime(true) * 1000);
    }

    /**
     * Write an assistant message (LLM turn with text and/or tool_use blocks).
     */
    public function writeAssistant(AssistantMessage $message, ?string $parentToolUseId = null): void
    {
        $content = [];

        foreach ($message->content as $block) {
            $content[] = $this->serializeContentBlock($block);
        }

        $event = [
            'type' => 'assistant',
            'message' => [
                'role' => 'assistant',
                'content' => $content,
            ],
            'parent_tool_use_id' => $parentToolUseId,
            'session_id' => $this->sessionId,
            'uuid' => $this->uuid(),
        ];

        // Include per-turn usage if available (extension over CC format
        // for real-time token tracking in the process monitor)
        if ($message->usage) {
            $event['usage'] = [
                'inputTokens' => $message->usage->inputTokens,
                'outputTokens' => $message->usage->outputTokens,
                'cacheReadInputTokens' => $message->usage->cacheReadInputTokens ?? 0,
                'cacheCreationInputTokens' => $message->usage->cacheCreationInputTokens ?? 0,
            ];
        }

        $this->writeLine($event);
    }

    /**
     * Write a tool_use event (when the LLM decides to call a tool).
     *
     * This is a convenience method that emits a minimal assistant message
     * containing a single tool_use block — useful when streaming individual
     * tool calls rather than complete AssistantMessages.
     */
    public function writeToolUse(string $toolName, string $toolUseId, array $input): void
    {
        $this->writeLine([
            'type' => 'assistant',
            'message' => [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => $toolUseId,
                        'name' => $toolName,
                        'input' => empty($input) ? (object) [] : $input,
                    ],
                ],
            ],
            'parent_tool_use_id' => null,
            'session_id' => $this->sessionId,
            'uuid' => $this->uuid(),
        ]);
    }

    /**
     * Write a tool_result event (after a tool finishes execution).
     *
     * In CC's format this is a "user" message with parent_tool_use_id set.
     */
    public function writeToolResult(
        string $toolUseId,
        string $toolName,
        string $result,
        bool $isError = false,
    ): void {
        $content = [
            [
                'type' => 'tool_result',
                'tool_use_id' => $toolUseId,
                'content' => mb_strlen($result) > 500
                    ? mb_substr($result, 0, 500) . '...'
                    : $result,
            ],
        ];

        if ($isError) {
            $content[0]['is_error'] = true;
        }

        $this->writeLine([
            'type' => 'user',
            'message' => [
                'role' => 'user',
                'content' => $content,
            ],
            'parent_tool_use_id' => $toolUseId,
            'session_id' => $this->sessionId,
            'uuid' => $this->uuid(),
        ]);
    }

    /**
     * Write a successful result event (agent execution completed).
     */
    public function writeResult(
        int $numTurns,
        string $resultText,
        array $usage,
        float $costUsd = 0.0,
    ): void {
        $durationMs = (int) (microtime(true) * 1000) - $this->startTimeMs;

        $this->writeLine([
            'type' => 'result',
            'subtype' => 'success',
            'duration_ms' => $durationMs,
            'duration_api_ms' => $durationMs,
            'is_error' => false,
            'num_turns' => $numTurns,
            'result' => mb_strlen($resultText) > 1000
                ? mb_substr($resultText, 0, 1000) . '...'
                : $resultText,
            'total_cost_usd' => $costUsd,
            'usage' => [
                'inputTokens' => $usage['input_tokens'] ?? 0,
                'outputTokens' => $usage['output_tokens'] ?? 0,
                'cacheReadInputTokens' => $usage['cache_read_input_tokens'] ?? 0,
                'cacheCreationInputTokens' => $usage['cache_creation_input_tokens'] ?? 0,
            ],
            'session_id' => $this->sessionId,
            'uuid' => $this->uuid(),
        ]);
    }

    /**
     * Write an error result event.
     */
    public function writeError(string $error, string $subtype = 'error_during_execution'): void
    {
        $durationMs = (int) (microtime(true) * 1000) - $this->startTimeMs;

        $this->writeLine([
            'type' => 'result',
            'subtype' => $subtype,
            'duration_ms' => $durationMs,
            'duration_api_ms' => $durationMs,
            'is_error' => true,
            'num_turns' => 0,
            'errors' => [$error],
            'session_id' => $this->sessionId,
            'uuid' => $this->uuid(),
        ]);
    }

    /**
     * Serialize a ContentBlock to CC-compatible array format.
     */
    private function serializeContentBlock(ContentBlock $block): array
    {
        return match ($block->type) {
            'text' => [
                'type' => 'text',
                'text' => $block->text ?? '',
            ],
            'tool_use' => [
                'type' => 'tool_use',
                'id' => $block->toolUseId ?? '',
                'name' => $block->toolName ?? 'unknown',
                'input' => empty($block->toolInput) ? (object) [] : $block->toolInput,
            ],
            'thinking' => [
                'type' => 'thinking',
                'thinking' => $block->thinking ?? '',
            ],
            default => [
                'type' => $block->type,
            ],
        };
    }

    /**
     * Write a single NDJSON line to the stream.
     *
     * Escapes U+2028 and U+2029 line separators that are valid JSON but
     * break line-by-line NDJSON parsers (matches CC's ndjsonSafeStringify).
     */
    private function writeLine(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        // CC's ndjsonSafeStringify escapes JS line terminators
        $json = str_replace(
            ["\u{2028}", "\u{2029}"],
            ['\\u2028', '\\u2029'],
            $json
        );

        fwrite($this->stream, $json . "\n");
    }

    private function uuid(): string
    {
        // RFC 4122 v4 UUID
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
