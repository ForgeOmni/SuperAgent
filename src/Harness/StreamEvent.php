<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

/**
 * Unified stream event hierarchy for the agent harness.
 *
 * Every observable moment in the agentic loop emits a StreamEvent.
 * Consumers (REPL renderer, NDJSON writer, WebSocket bridge, etc.)
 * can pattern-match on the concrete subclass.
 */
abstract class StreamEvent
{
    public readonly float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    /**
     * Machine-readable event type slug.
     */
    abstract public function type(): string;

    /**
     * Serialize to a plain array for JSON / NDJSON transport.
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * Serialize to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
