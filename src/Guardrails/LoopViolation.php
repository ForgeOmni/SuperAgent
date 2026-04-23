<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

/**
 * Value object returned when `LoopDetector` decides a detector has
 * tripped. Callers (typically the harness loop) use:
 *
 *   - `$type` to decide how to react (different detectors may warrant
 *     different UX — a TOOL_LOOP is often "retry with an explicit
 *     instruction to invoke different tools", a CONTENT_LOOP is
 *     closer to "stop and surface to the user")
 *   - `$message` as human-readable detail for logs / UI
 *   - `$metadata` for structured fields per detector (tool name,
 *     repetition count, repeated chunk, window size, etc.)
 */
final class LoopViolation
{
    public function __construct(
        public readonly LoopType $type,
        public readonly string $message,
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}
