<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider exposes a document OCR / layout parsing endpoint that returns
 * structured text (not just a raw transcription) — GLM's Layout Parsing
 * (GLM-OCR), Qwen's `qwen-vl-ocr` multimodal path.
 *
 * Kept as a standalone interface because OCR is sometimes reachable without
 * the full chat pipeline and because Tool wrappers want to call it directly.
 */
interface SupportsOCR
{
    /**
     * Recognise text (and optionally layout) from a document image or PDF.
     *
     * `$source` may be a local path, a URL, or a provider-scoped file id
     * that was previously uploaded via `SupportsFileExtract::uploadForExtract()`.
     * Implementations document which forms they accept.
     *
     * @param array<string, mixed> $opts Provider-specific options
     *                                    (language hints, page range, …).
     * @return array{text: string, blocks?: array<int, array<string, mixed>>, raw?: mixed}
     */
    public function ocr(string $source, array $opts = []): array;
}
