<?php

declare(strict_types=1);

namespace SuperAgent\Streaming;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\StreamingHandler;

/**
 * Turns a run's stream into Server-Sent Events, for a web request.
 *
 * `StreamingHandler` is a set of callbacks; a CLI prints them. A web host
 * has to put them on the wire in a format a browser's `EventSource` reads,
 * and every example of that lived in this SDK's console layer — so hosts
 * either pulled in `symfony/console` for a web response or re-derived the
 * framing themselves, usually forgetting the parts that make streaming
 * actually stream through a proxy.
 *
 * The sink is a callable, so this works with `StreamedResponse`, with plain
 * `echo`, with a PSR-7 stream, or with a string buffer in a test:
 *
 *     $emitter = new SseEmitter(function (string $frame): void {
 *         echo $frame;
 *         ob_flush();
 *         flush();
 *     });
 *
 *     return response()->stream(function () use ($agent, $emitter) {
 *         $agent->prompt($prompt, $emitter->handler());
 *         $emitter->close();
 *     }, 200, SseEmitter::HEADERS);
 *
 * @since 1.2.0
 */
final class SseEmitter
{
    /**
     * Headers an SSE response needs. `X-Accel-Buffering` is the one people
     * discover last: without it nginx buffers the whole response and the
     * stream arrives as one block at the end, which looks exactly like a
     * streaming bug in the application.
     */
    public const HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache, no-transform',
        'Connection' => 'keep-alive',
        'X-Accel-Buffering' => 'no',
    ];

    /** @var callable(string): void */
    private $sink;

    private bool $closed = false;

    /** @param callable(string): void $sink receives each complete SSE frame */
    public function __construct(callable $sink)
    {
        $this->sink = $sink;
    }

    /**
     * A handler that writes every event of a run to the sink.
     *
     * Pass it to `Agent::prompt()` / `Agent::stream()`; it carries no console
     * dependency and nothing about it is Laravel-specific.
     */
    public function handler(): StreamingHandler
    {
        return new StreamingHandler(
            onText: fn (string $delta, string $full) => $this->send('text', [
                'delta' => $delta,
            ]),
            onThinking: fn (string $delta, string $full) => $this->send('thinking', [
                'delta' => $delta,
            ]),
            onToolUse: fn (ContentBlock $block) => $this->send('tool_use', [
                'id' => $block->toolUseId,
                'name' => $block->toolName,
            ]),
            onToolResult: fn (string $toolUseId, string $toolName, string $result, bool $isError) => $this->send('tool_result', [
                'id' => $toolUseId,
                'name' => $toolName,
                'is_error' => $isError,
            ]),
            onFinalMessage: fn (AssistantMessage $message) => $this->send('final', [
                'text' => $message->text(),
                'stop_reason' => $message->stopReason?->value,
            ]),
        );
    }

    /**
     * Send one event. Data is JSON, on a single `data:` line — a raw newline
     * inside a frame ends it, which is how half a model's answer ends up as
     * a malformed event.
     *
     * @param array<string,mixed> $data
     */
    public function send(string $event, array $data): void
    {
        if ($this->closed) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        ($this->sink)("event: {$event}\ndata: " . ($json === false ? '{}' : $json) . "\n\n");
    }

    /**
     * A comment frame, which browsers ignore. Send one every 15-30 seconds
     * while a turn is thinking: proxies close a connection that has been
     * silent, and a long tool call is silent.
     */
    public function keepAlive(): void
    {
        if (! $this->closed) {
            ($this->sink)(": keep-alive\n\n");
        }
    }

    /** Tell the client the run is over; further sends are ignored. */
    public function close(array $data = []): void
    {
        if ($this->closed) {
            return;
        }

        $this->send('done', $data);
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
