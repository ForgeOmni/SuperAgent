<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Embeddings;

/**
 * Adapts an existing closure-shaped embedder to the `EmbeddingProvider`
 * interface. The closure may take either:
 *
 *   - `fn (list<string>): list<list<float>>` — preferred batch shape
 *   - `fn (string): list<float>`             — single-text shape (the
 *     legacy `VectorMemoryProvider` form)
 *
 * The adapter detects the parameter type at call time and dispatches
 * accordingly. Use this when the host wires an embedder via DI / config
 * before the typed providers existed.
 */
final class CallableEmbeddingProvider implements EmbeddingProvider
{
    /** @var \Closure */
    private $fn;

    public function __construct(
        callable $fn,
        private readonly int $dimensions = 0,
        private readonly string $fingerprint = 'callable:unknown',
    ) {
        $this->fn = \Closure::fromCallable($fn);
    }

    public function embed(array $texts): array
    {
        if ($texts === []) return [];

        $shape = $this->detectShape();
        if ($shape === 'batch') {
            $out = ($this->fn)($texts);
            return is_array($out) ? array_values(array_map(static fn ($v) => is_array($v) ? array_values($v) : [], $out)) : [];
        }

        // Single-text shape: call once per row.
        $out = [];
        foreach ($texts as $t) {
            $vec = ($this->fn)((string) $t);
            $out[] = is_array($vec) ? array_values($vec) : [];
        }
        return $out;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    private function detectShape(): string
    {
        try {
            $ref = new \ReflectionFunction($this->fn);
            $params = $ref->getParameters();
            if ($params === []) return 'batch';
            $type = $params[0]->getType();
            if ($type instanceof \ReflectionNamedType) {
                $name = $type->getName();
                if ($name === 'string') return 'single';
                if ($name === 'array' || str_ends_with($name, 'iterable')) return 'batch';
            }
        } catch (\Throwable) {
            // Fall through.
        }
        return 'batch';
    }
}
