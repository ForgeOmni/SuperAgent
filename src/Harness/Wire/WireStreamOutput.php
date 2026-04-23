<?php

declare(strict_types=1);

namespace SuperAgent\Harness\Wire;

/**
 * Stream every `WireEvent` fed in to a writable resource as one-line
 * NDJSON. The stdio MVP of the ACP IDE bridge.
 *
 * Usage pattern (from a CLI entry point):
 *
 *   $out = new WireStreamOutput(STDOUT);
 *   foreach ($agentHarness->stream($prompt) as $event) {
 *       if ($event instanceof WireEvent) {
 *           $out->emit($event);
 *       }
 *   }
 *
 * An IDE plugin / CI job spawns `superagent --output json-stream`,
 * pipes stdout through a per-line JSON parser, and reacts to events
 * as they arrive. Each line is self-describing (`wire_version` +
 * `type` at top level), so even a zero-state consumer can latch onto
 * the stream mid-flight.
 *
 * This is the Phase-8c MVP — a stdio bridge is enough for
 * pipe-driven IDE integrations. Full socket-/HTTP-based ACP (the
 * kind kimi-cli's ACP server exposes) lives on top of this same
 * renderer in a follow-up; the serialization layer stays the same.
 */
final class WireStreamOutput
{
    /** @var resource */
    private $stream;

    private bool $flushAfterWrite;

    /**
     * @param resource $stream Writable resource — typically STDOUT, a
     *                         file handle, or a socket. The caller
     *                         owns its lifecycle; we never close it.
     * @param bool $flushAfterWrite Force-flush after each event so
     *                         downstream IDE processes see events at
     *                         real-time pacing instead of whenever
     *                         the stdio buffer happens to flush.
     *                         Default true — you almost always want
     *                         this for an interactive stream, and
     *                         NDJSON batching is cheap enough that
     *                         the per-event flush cost is negligible.
     */
    public function __construct($stream, bool $flushAfterWrite = true)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('WireStreamOutput: $stream must be a writable resource');
        }
        $this->stream = $stream;
        $this->flushAfterWrite = $flushAfterWrite;
    }

    /**
     * Emit a single event. Returns the number of bytes written, or
     * 0 on write failure — we swallow the error so a dead peer (e.g.
     * IDE plugin disconnected) doesn't crash the agent loop. Callers
     * that care about back-pressure can check the return value.
     */
    public function emit(WireEvent $event): int
    {
        $bytes = JsonStreamRenderer::emit($event, $this->stream);
        if ($this->flushAfterWrite) {
            @fflush($this->stream);
        }
        return $bytes;
    }

    /**
     * Emit many events in a row. Convenience wrapper for
     * bulk-replay scenarios (e.g. fast-forwarding a saved session
     * into an IDE that just attached).
     *
     * @param iterable<WireEvent> $events
     */
    public function emitAll(iterable $events): int
    {
        $total = 0;
        foreach ($events as $event) {
            $total += $this->emit($event);
        }
        return $total;
    }
}
