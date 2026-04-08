<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use SuperAgent\Memory\Contracts\MemoryProviderInterface;
use SuperAgent\Memory\Storage\MemoryStorageInterface;

/**
 * Always-on builtin memory provider backed by MEMORY.md files.
 *
 * This is the default provider that reads/writes to the filesystem-based
 * memory storage. It's always active alongside any external provider.
 */
class BuiltinMemoryProvider implements MemoryProviderInterface
{
    private ?MemoryStorageInterface $storage = null;
    private ?MemoryRetriever $retriever = null;
    private ?MemoryExtractor $extractor = null;

    public function __construct(
        private ?MemoryConfig $config = null,
    ) {}

    public function getName(): string
    {
        return 'builtin';
    }

    public function initialize(array $config = []): void
    {
        // Storage and retriever are typically injected or created externally
    }

    /**
     * Set the storage backend.
     */
    public function setStorage(MemoryStorageInterface $storage): void
    {
        $this->storage = $storage;
    }

    /**
     * Set the retriever for search operations.
     */
    public function setRetriever(MemoryRetriever $retriever): void
    {
        $this->retriever = $retriever;
    }

    /**
     * Set the extractor for memory extraction operations.
     */
    public function setExtractor(MemoryExtractor $extractor): void
    {
        $this->extractor = $extractor;
    }

    public function onTurnStart(string $userMessage, array $conversationHistory): ?string
    {
        if ($this->retriever === null) {
            return null;
        }

        $memories = $this->retriever->findRelevant($userMessage, 3);
        if (empty($memories)) {
            return null;
        }

        $parts = [];
        foreach ($memories as $memory) {
            $parts[] = "## {$memory->name} ({$memory->type->value})\n{$memory->content}";
        }

        return implode("\n\n", $parts);
    }

    public function onTurnEnd(array $assistantResponse, array $conversationHistory): void
    {
        // Extraction happens via SessionMemoryExtractor at thresholds
    }

    public function onPreCompress(array $messagesToCompress): void
    {
        // Could trigger memory extraction before compression
        // Currently handled by SessionMemoryExtractor
    }

    public function onSessionEnd(array $fullConversation): void
    {
        if ($this->extractor !== null) {
            $this->extractor->extractFromConversation($fullConversation);
        }
    }

    public function onMemoryWrite(string $key, string $content, array $metadata = []): void
    {
        // Already writing to storage — no mirroring needed for builtin
    }

    public function search(string $query, int $maxResults = 5): array
    {
        if ($this->retriever === null) {
            return [];
        }

        $memories = $this->retriever->findRelevant($query, $maxResults);

        return array_map(fn(Memory $m) => [
            'content' => $m->content,
            'relevance' => 1.0, // Builtin doesn't score
            'source' => $m->name,
            'type' => $m->type->value,
        ], $memories);
    }

    public function isReady(): bool
    {
        return $this->storage !== null;
    }

    public function shutdown(): void
    {
        // Nothing to clean up
    }
}
