<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Injection;

/**
 * Injection patterns for one language.
 *
 * The detector's rules were English regexes — "ignore previous instructions",
 * "you are now". A host whose untrusted text is an order note in Chinese, a
 * support ticket in French or a product description in German got a clean
 * scan on every one of them, which is worse than no scan: it reads as
 * evidence that nothing was found.
 *
 * A pack is a language tag plus category => regex list. The `universal` pack
 * holds the rules that do not depend on language at all — invisible Unicode,
 * hidden HTML, shell exfiltration, encoded payloads — and is always applied.
 *
 * @since 1.2.0
 */
final class PatternPack
{
    /**
     * @param string $language BCP-47-ish tag, or `universal`
     * @param array<string, list<string>> $patterns category => regexes
     */
    public function __construct(
        public readonly string $language,
        public readonly array $patterns,
    ) {
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_keys($this->patterns);
    }

    /** @return list<string> */
    public function patternsFor(string $category): array
    {
        return $this->patterns[$category] ?? [];
    }
}
