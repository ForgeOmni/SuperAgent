<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Contracts;

/**
 * Pluggable memory provider interface for external memory backends.
 *
 * Inspired by hermes-agent's MemoryProvider abstract base class — enables:
 *   - Builtin memory (MEMORY.md/USER.md) always active
 *   - At most ONE external provider active at a time
 *   - Lifecycle hooks for turn-level, session-level, and compression events
 *   - Memory injection wrapped in safe context blocks
 *
 * Implementations:
 *   - BuiltinMemoryProvider (always on, file-based MEMORY.md)
 *   - External providers: vector stores, episodic memory, user modeling
 *
 * Usage:
 *   $manager = new MemoryProviderManager($builtinProvider);
 *   $manager->setExternalProvider(new VectorMemoryProvider($config));
 *   $context = $manager->buildContextBlock($query);
 */
interface MemoryProviderInterface
{
    /**
     * Get the provider name (e.g., 'builtin', 'vector', 'episodic').
     */
    public function getName(): string;

    /**
     * Initialize the provider (called once at session start).
     */
    public function initialize(array $config = []): void;

    /**
     * Called at the start of each turn — inject relevant context.
     *
     * @return string|null Context to inject into the system prompt, or null
     */
    public function onTurnStart(string $userMessage, array $conversationHistory): ?string;

    /**
     * Called after each turn completes — sync new information.
     */
    public function onTurnEnd(array $assistantResponse, array $conversationHistory): void;

    /**
     * Called before context compression — extract memories before they're lost.
     */
    public function onPreCompress(array $messagesToCompress): void;

    /**
     * Called at session end — extract and persist long-term memories.
     */
    public function onSessionEnd(array $fullConversation): void;

    /**
     * Called when a memory write occurs (mirror to external provider).
     */
    public function onMemoryWrite(string $key, string $content, array $metadata = []): void;

    /**
     * Search for relevant memories given a query.
     *
     * @return array Array of memory items with 'content', 'relevance', 'source' keys
     */
    public function search(string $query, int $maxResults = 5): array;

    /**
     * Check if the provider is healthy and ready.
     */
    public function isReady(): bool;

    /**
     * Shut down the provider (cleanup resources).
     */
    public function shutdown(): void;
}
