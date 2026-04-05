<?php

declare(strict_types=1);

namespace SuperAgent\Logging;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;

/**
 * Factory for creating a StreamingHandler that writes CC-compatible NDJSON
 * to a log file. This is the one-liner integration point for in-process
 * agent execution (i.e. direct $agent->prompt() calls that don't go
 * through agent-runner.php / ProcessBackend).
 *
 * Usage:
 *   $handler = NdjsonStreamingHandler::create('/tmp/agent.jsonl', 'my-agent');
 *   $result  = $agent->prompt($prompt, $handler);
 *
 * The log file will contain one NDJSON line per event, identical to what
 * agent-runner.php emits on stderr — parseable by CC's bridge/sessionRunner
 * extractActivities() and SuperAgent's ProcessBackend::poll().
 */
class NdjsonStreamingHandler
{
    /**
     * Create a StreamingHandler that writes all execution events as NDJSON.
     *
     * @param string|resource $logTarget  File path (string) or writable stream resource
     * @param string          $agentId    Agent identifier for session_id field
     * @param bool            $append     Append to existing file (default true)
     */
    public static function create(
        mixed $logTarget,
        string $agentId = 'agent',
        bool $append = true,
    ): StreamingHandler {
        // Open file or use provided stream
        if (is_string($logTarget)) {
            $dir = dirname($logTarget);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $stream = fopen($logTarget, $append ? 'a' : 'w');
            if ($stream === false) {
                throw new \RuntimeException("Cannot open log file: {$logTarget}");
            }
        } else {
            $stream = $logTarget;
        }

        $ndjson = new NdjsonWriter($agentId, sessionId: $agentId, stream: $stream);

        return new StreamingHandler(
            onToolUse: function (ContentBlock $block) use ($ndjson) {
                $ndjson->writeToolUse(
                    $block->toolName ?? 'unknown',
                    $block->toolUseId ?? '',
                    $block->toolInput ?? [],
                );
            },
            onToolResult: function (string $toolUseId, string $toolName, string $result, bool $isError) use ($ndjson) {
                $ndjson->writeToolResult($toolUseId, $toolName, $result, $isError);
            },
            onTurn: function (AssistantMessage $message, int $turnNumber) use ($ndjson) {
                $ndjson->writeAssistant($message);
            },
        );
    }

    /**
     * Get the NdjsonWriter from a handler created by this factory,
     * so you can call writeResult() / writeError() after execution.
     *
     * Usage:
     *   $handler = NdjsonStreamingHandler::createWithWriter('/tmp/agent.jsonl', 'my-agent');
     *   $result  = $agent->prompt($prompt, $handler->handler);
     *   $handler->writer->writeResult($result->turns(), $result->text(), [...]);
     *
     * @return object{handler: StreamingHandler, writer: NdjsonWriter}
     */
    public static function createWithWriter(
        mixed $logTarget,
        string $agentId = 'agent',
        bool $append = true,
    ): object {
        if (is_string($logTarget)) {
            $dir = dirname($logTarget);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $stream = fopen($logTarget, $append ? 'a' : 'w');
            if ($stream === false) {
                throw new \RuntimeException("Cannot open log file: {$logTarget}");
            }
        } else {
            $stream = $logTarget;
        }

        $ndjson = new NdjsonWriter($agentId, sessionId: $agentId, stream: $stream);

        $handler = new StreamingHandler(
            onToolUse: function (ContentBlock $block) use ($ndjson) {
                $ndjson->writeToolUse(
                    $block->toolName ?? 'unknown',
                    $block->toolUseId ?? '',
                    $block->toolInput ?? [],
                );
            },
            onToolResult: function (string $toolUseId, string $toolName, string $result, bool $isError) use ($ndjson) {
                $ndjson->writeToolResult($toolUseId, $toolName, $result, $isError);
            },
            onTurn: function (AssistantMessage $message, int $turnNumber) use ($ndjson) {
                $ndjson->writeAssistant($message);
            },
        );

        return (object) ['handler' => $handler, 'writer' => $ndjson];
    }
}
