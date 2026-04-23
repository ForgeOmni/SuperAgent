<?php

declare(strict_types=1);

namespace SuperAgent\Harness\Wire;

/**
 * Turn a sequence of WireEvents into newline-delimited JSON.
 *
 * Wire layout:
 *   {"wire_version": 1, "type": "turn.begin", ...}\n
 *   {"wire_version": 1, "type": "tool.call",  ...}\n
 *   {"wire_version": 1, "type": "usage",      ...}\n
 *   {"wire_version": 1, "type": "turn.end",   ...}\n
 *
 * Each event is exactly one JSON object on one line — no trailing
 * whitespace, no pretty-printing. That keeps the stream parseable by
 * simple readers (`jq -c`, pipelines, editor integrations) without a
 * framing layer on top.
 *
 * This renderer is intentionally a thin wrapper around `json_encode`
 * so the cost of adoption is near-zero: any caller that already has
 * a WireEvent can emit it with one method call.
 */
class JsonStreamRenderer
{
    /**
     * Render to a resource (stdout, file handle, socket). Returns the
     * number of bytes written.
     *
     * @param resource $stream
     */
    public static function emit(WireEvent $event, $stream): int
    {
        $payload = self::format($event);
        return (int) @fwrite($stream, $payload);
    }

    /**
     * Format the event into its canonical one-line JSON representation.
     * Callers who want to batch writes or buffer output can use this
     * and drive the I/O themselves.
     */
    public static function format(WireEvent $event): string
    {
        $payload = $event->toArray();
        // Guardrail: the `WireEvent` contract says the dict carries
        // `type` and `wire_version` — if an implementation forgets,
        // we patch them in so the stream stays self-describing.
        $payload['type'] ??= $event->eventType();
        $payload['wire_version'] ??= $event->wireVersion();

        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
