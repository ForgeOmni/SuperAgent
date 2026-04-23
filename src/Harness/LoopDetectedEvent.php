<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

use SuperAgent\Guardrails\LoopType;
use SuperAgent\Guardrails\LoopViolation;

/**
 * A pathological loop has been detected in the current agent run.
 *
 * Projected onto the wire stream by `LoopDetectionHarness` when
 * `LoopDetector` trips. Consumers (TUI, ACP IDE bridge, stream-json
 * pipelines) render it to the user and decide whether to stop the
 * turn or just warn — policy lives at the caller, this event only
 * carries the signal.
 *
 * Wire shape:
 *   {
 *     "wire_version": 1,
 *     "type": "loop_detected",
 *     "timestamp": ...,
 *     "loop_type": "tool_loop" | "stagnation" | "file_read_loop" |
 *                  "content_loop" | "thought_loop",
 *     "message":   "Tool 'Edit' called 5 times with identical arguments",
 *     "metadata":  { ... detector-specific fields ... }
 *   }
 *
 * Inherits WireEvent compliance from the Phase-8b StreamEvent base.
 */
class LoopDetectedEvent extends StreamEvent
{
    public function __construct(
        public readonly LoopType $loopType,
        public readonly string $message,
        /** @var array<string, mixed> */
        public readonly array $metadata = [],
    ) {
        parent::__construct();
    }

    public static function fromViolation(LoopViolation $v): self
    {
        return new self(
            loopType: $v->type,
            message: $v->message,
            metadata: $v->metadata,
        );
    }

    public function type(): string
    {
        return 'loop_detected';
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'loop_type' => $this->loopType->value,
            'message'   => $this->message,
            'metadata'  => $this->metadata,
        ];
    }
}
