<?php

declare(strict_types=1);

namespace SuperAgent\Harness\Wire;

/**
 * Marker contract for the unified Wire event stream (v1).
 *
 * STATUS: **foundation only** — the 14 concrete event classes under
 * `src/Harness/*Event.php` have not yet been migrated onto this
 * interface. See `docs/WIRE_PROTOCOL.md` for the roadmap and the
 * target per-event shape. Callers that want to write schema-stable
 * stream consumers today should still go through the existing
 * `StreamEvent` hierarchy; this interface will become authoritative
 * when the migration lands.
 *
 * Design goals (inspired by kimi-cli's `src/kimi_cli/wire/types.py`):
 *   - A single serialization boundary: TUI / ACP IDE server /
 *     `--output json-stream` all consume the same event stream.
 *   - Versioned. A consumer pinning `wire_version: 1` gets a stable
 *     schema even if internal event fields get added later.
 *   - Self-describing. `type` on every payload lets a consumer
 *     deserialize without prior knowledge of the full event catalog.
 */
interface WireEvent
{
    /**
     * Wire protocol version this event conforms to. Bump when breaking
     * a consumer contract — adding optional fields does NOT require a
     * bump.
     */
    public function wireVersion(): int;

    /**
     * Short string identifier ("turn.begin", "tool.call", "usage",
     * "compaction.begin", …). Consumers switch on this.
     */
    public function eventType(): string;

    /**
     * JSON-serializable payload. MUST include `type` (mirror of
     * `eventType()`) and `wire_version` at the top level so the
     * stream stays self-describing even when readers only hold the
     * raw dict.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
