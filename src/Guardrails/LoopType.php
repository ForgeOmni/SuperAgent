<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

/**
 * What kind of loop pattern tripped `LoopDetector`. Values are
 * stable wire identifiers — emitted on `LoopViolation::$type` and
 * surfaced through the wire protocol's future `loop_detected`
 * event family, so changing them is a breaking change for
 * pipeline consumers.
 */
enum LoopType: string
{
    case ToolLoop     = 'tool_loop';
    case Stagnation   = 'stagnation';
    case FileReadLoop = 'file_read_loop';
    case ContentLoop  = 'content_loop';
    case ThoughtLoop  = 'thought_loop';
}
