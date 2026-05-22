<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Compression;

/**
 * Per-tool output compressor. Implementations live in `Compressors/`.
 *
 * Contract:
 *  - Pure function: same input → same output.
 *  - Lossy-safe: model-meaningful structure (paths, line numbers,
 *    diff hunks, match locations) MUST be preserved verbatim.
 *  - Returns null when the input doesn't match this compressor's format
 *    (RtkPipeline falls back to passing the original through).
 *  - Never throws on malformed input — return null instead.
 */
interface CompressorInterface
{
    public function compress(string $output): ?string;
}
