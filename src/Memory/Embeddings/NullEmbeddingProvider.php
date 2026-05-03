<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Embeddings;

/**
 * Returns an empty vector for every input. Useful in:
 *   - unit tests that need to satisfy the type without spinning up an
 *     actual model;
 *   - dev environments where the operator wants to disable semantic
 *     features without rewriting the wiring.
 *
 * Consumers must treat `[]` as "no signal" and fall back to whatever
 * baseline they have (BM25, keyword overlap). `SemanticSkillRouter`
 * already does — see its `keywordFallback()`.
 */
final class NullEmbeddingProvider implements EmbeddingProvider
{
    public function embed(array $texts): array
    {
        return array_fill(0, count($texts), []);
    }

    public function dimensions(): int
    {
        return 0;
    }

    public function fingerprint(): string
    {
        return 'null:0';
    }
}
