<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider exposes server-side web search as a standalone call — not just
 * an in-chat builtin tool (GLM `/web_search`, Kimi `$web_search` augmented
 * chat).
 *
 * Implementations return a normalised result list so the call site (agent
 * loop, Tool wrapper) can treat all providers uniformly. The shape is
 * loosely modelled after search-engine result pages — title / url / snippet
 * are the only reliably-present fields across providers.
 */
interface SupportsWebSearch
{
    /**
     * Run a web search and return normalised hits.
     *
     * @param array<string, mixed> $opts Provider-specific hints
     *                                   (count, language, freshness, …).
     * @return array<int, array{title: string, url: string, snippet?: string}>
     */
    public function webSearch(string $query, array $opts = []): array;
}
