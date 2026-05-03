<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin\Symbols;

/**
 * Tries each extractor in order; the first that `supports()` the file AND
 * returns a non-empty map wins. Designed to layer a precise-but-optional
 * extractor (tree-sitter, LSP) in front of the always-on regex fallback.
 *
 * Empty-but-supports counts as "skip and try the next one" — so a
 * tree-sitter extractor that crashes on a particular file gracefully
 * yields the regex extractor's output instead of a blank `symbol` field.
 */
final class CompositeSymbolExtractor implements SymbolExtractor
{
    /** @param SymbolExtractor[] $chain  ordered, highest-precision first */
    public function __construct(private readonly array $chain) {}

    public function supports(string $file, ?string $langHint = null): bool
    {
        foreach ($this->chain as $extractor) {
            if ($extractor->supports($file, $langHint)) return true;
        }
        return false;
    }

    public function extract(string $file, array $lines, ?string $langHint = null): array
    {
        foreach ($this->chain as $extractor) {
            if (!$extractor->supports($file, $langHint)) continue;
            $map = $extractor->extract($file, $lines, $langHint);
            if ($map !== []) return $map;
        }
        return [];
    }
}
