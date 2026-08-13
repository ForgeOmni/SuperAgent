<?php

declare(strict_types=1);

namespace SuperAgent\Spill;

/**
 * Reference to a spilled (disk-persisted) tool output.
 *
 * The locator is an opaque handle (`spill://<sessionKey>/<file>`) — never a
 * raw filesystem path — so the store controls resolution and containment.
 * Borrowed from deepseek-harness's spill seam: oversized tool output is
 * persisted and the model receives a compact preview plus this reference,
 * instead of losing the content to truncation.
 */
class SpillRef
{
    public function __construct(
        public readonly string $locator,
        public readonly int $bytes,
        public readonly string $retrievalHint = 'Use the spill_read tool with this locator to read the full output in chunks (offset/limit supported).',
    ) {
    }
}
