<?php

declare(strict_types=1);

namespace SuperAgent\Support;

/**
 * Small helper for W3C Trace Context values — `traceparent` and
 * `tracestate` per W3C recommendation
 * (https://www.w3.org/TR/trace-context/).
 *
 * We deliberately do NOT pull in an OpenTelemetry SDK: the majority
 * of SuperAgent hosts don't run one, and the single use case we have
 * for W3C values today is pinning them as `client_metadata` on the
 * OpenAI Responses API (so provider-side logs line up with whatever
 * trace the host is running).
 *
 * Parsing is strict — `traceparent` must match the canonical
 * `00-<trace-id>-<span-id>-<flags>` shape. Generation is non-strict:
 * if a caller can't be bothered to pass a full context, we mint a
 * random one with the "sampled" flag set so downstream tools don't
 * drop the span on the floor.
 */
final class TraceContext
{
    public readonly string $traceparent;
    public readonly ?string $tracestate;

    public function __construct(string $traceparent, ?string $tracestate = null)
    {
        $this->traceparent = $traceparent;
        $this->tracestate  = $tracestate;
    }

    /**
     * Generate a fresh W3C traceparent value with a random 16-byte
     * trace-id + 8-byte span-id, flags `01` (sampled).
     *
     * Version byte is `00` per the v1 spec (the only version any
     * tooling understands today).
     */
    public static function fresh(): self
    {
        $traceId = bin2hex(random_bytes(16));
        $spanId  = bin2hex(random_bytes(8));
        return new self(sprintf('00-%s-%s-01', $traceId, $spanId));
    }

    /**
     * Parse a string formatted as `00-<trace-id>-<span-id>-<flags>`.
     * Returns null on any deviation — callers that want a fail-open
     * behaviour should fall back to {@see self::fresh()} themselves.
     */
    public static function parse(string $traceparent): ?self
    {
        if (preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/', $traceparent) === 1) {
            return new self($traceparent);
        }
        return null;
    }

    /**
     * Project this context into OpenAI's `client_metadata` envelope
     * using the keys codex-rs uses (`traceparent` / `tracestate`).
     * OpenAI treats these as opaque strings; the only requirement is
     * length ≤ ~512 chars and no control characters.
     *
     * @return array<string, string>
     */
    public function asClientMetadata(): array
    {
        $out = ['traceparent' => $this->traceparent];
        if ($this->tracestate !== null && $this->tracestate !== '') {
            $out['tracestate'] = $this->tracestate;
        }
        return $out;
    }
}
