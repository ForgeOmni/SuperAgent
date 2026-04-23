<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Harness\Wire\WireEvent;

/**
 * Unified stream event hierarchy for the agent harness.
 *
 * Every observable moment in the agentic loop emits a StreamEvent.
 * Consumers (REPL renderer, NDJSON writer, WebSocket bridge, etc.)
 * can pattern-match on the concrete subclass.
 *
 * Wire protocol v1: this class implements `WireEvent` so every
 * subclass is automatically compliant — `toArray()` carries
 * `wire_version` + `type` at the top level and `JsonStreamRenderer`
 * can emit any StreamEvent as a one-line NDJSON record. See
 * `docs/WIRE_PROTOCOL.md` for the full protocol shape.
 *
 * This is the Phase-8b migration's entry point: we upgrade the base
 * class once, every concrete subclass (TurnCompleteEvent,
 * ToolStartedEvent, ToolCompletedEvent, TextDeltaEvent,
 * ThinkingDeltaEvent, AgentCompleteEvent, CompactionEvent, ErrorEvent,
 * StatusEvent) inherits compliance for free. Subclasses whose
 * `toArray()` overrides call `parent::toArray()` keep working
 * byte-exactly except for the new `wire_version` key — an additive
 * change that pre-0.8.9 consumers ignore.
 */
abstract class StreamEvent implements WireEvent
{
    /**
     * Wire protocol version this hierarchy produces. Bump only on a
     * breaking field shape change; additive new fields do NOT require
     * a bump. See docs/WIRE_PROTOCOL.md §7.
     */
    public const WIRE_VERSION = 1;

    public readonly float $timestamp;

    public function __construct()
    {
        $this->timestamp = microtime(true);
    }

    /**
     * Machine-readable event type slug (e.g. "turn_complete").
     * Legacy shape — kept as-is so pre-0.8.9 consumers that pattern-
     * match on these exact strings continue to work.
     */
    abstract public function type(): string;

    /**
     * WireEvent contract — delegates to `type()` so the two surfaces
     * stay in sync. Subclasses don't need to implement this directly.
     */
    public function eventType(): string
    {
        return $this->type();
    }

    /**
     * WireEvent contract — everything in this hierarchy declares v1.
     */
    public function wireVersion(): int
    {
        return self::WIRE_VERSION;
    }

    /**
     * Serialize to a plain array for JSON / NDJSON transport.
     *
     * Carries `wire_version` at the top level (new in Phase 8b) so
     * consumers pinning `wire_version: 1` can detect future breaking
     * changes. `type` and `timestamp` are kept at their pre-existing
     * positions.
     */
    public function toArray(): array
    {
        return [
            'wire_version' => self::WIRE_VERSION,
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
