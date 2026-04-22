<?php

declare(strict_types=1);

namespace SuperAgent\Providers\Capabilities;

/**
 * Provider supports explicit context-cache breakpoints — Anthropic's
 * `cache_control: {type: "ephemeral"}` blocks, Kimi's automatic prompt cache
 * with an opt-in hint, etc.
 *
 * The interface is intentionally minimal: callers hand a content block to
 * `cacheBreakpoint()` and receive it back wrapped with whatever the provider
 * needs to mark the prefix as cacheable. Providers that cache automatically
 * without any client-side annotation can return the input unchanged.
 */
interface SupportsContextCaching
{
    /**
     * Wrap a content block (text, tool definition, system prompt segment)
     * with the provider's cache-breakpoint annotation. Returns the
     * augmented block; the caller is responsible for inserting it into the
     * message/tool stream at the point where caching should begin.
     *
     * @param array<string, mixed> $contentBlock
     * @return array<string, mixed>
     */
    public function cacheBreakpoint(array $contentBlock): array;
}
