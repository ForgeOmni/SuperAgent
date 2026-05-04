<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use SuperAgent\LLM\ProviderInterface;
use SuperAgent\Memory\Embeddings\EmbeddingProvider;
use SuperAgent\Memory\Storage\MemoryStorageInterface;
use SuperAgent\Memory\VectorIndex\IndexedItem;
use SuperAgent\Memory\VectorIndex\VectorIndex;

class MemoryRetriever
{
    public function __construct(
        private MemoryStorageInterface $storage,
        private ProviderInterface $provider,
        private MemoryConfig $config,
        private ?EmbeddingProvider $embedder = null,
        private ?VectorIndex $vectorIndex = null,
    ) {}

    /**
     * Find memories whose embedding is closest to the query's, using the
     * configured `VectorIndex`. Cheaper and more deterministic than
     * `findRelevant()`'s LLM-as-judge path — prefer this when an
     * `EmbeddingProvider` is wired.
     *
     * The first call lazy-indexes every memory currently in storage so
     * the `add` cost amortises across the session. Memory mutations
     * during the session must call `indexMemory()` to keep the index
     * fresh; we don't snoop the storage layer.
     *
     * @return Memory[] in descending similarity order
     */
    public function findBySimilarity(string $query, int $maxResults = 5, float $minScore = 0.0): array
    {
        if ($this->embedder === null || $this->vectorIndex === null) {
            // Not wired — fall back to the LLM judge.
            return $this->findRelevant($query, $maxResults);
        }

        if ($this->vectorIndex->count() === 0) {
            $this->seedIndexFromStorage();
        }
        if ($this->vectorIndex->count() === 0) {
            return [];
        }

        $vec = $this->embedder->embed([$query])[0] ?? [];
        if ($vec === []) return [];

        $hits = $this->vectorIndex->search($vec, $maxResults, $minScore);

        $memories = [];
        foreach ($hits as $hit) {
            $memory = $this->storage->load($hit->id);
            if ($memory === null) continue;
            $memory->markAccessed();
            $this->storage->save($memory);
            $memories[] = $memory;
        }
        return $memories;
    }

    /**
     * Add (or replace) a single memory's row in the vector index.
     * No-op when no embedder/index is wired so callers can sprinkle
     * this safely after every `save()`.
     */
    public function indexMemory(Memory $memory): void
    {
        if ($this->embedder === null || $this->vectorIndex === null) return;
        $text = trim($memory->name . "\n" . $memory->description . "\n" . $memory->content);
        if ($text === '') return;
        $vec = $this->embedder->embed([$text])[0] ?? [];
        if ($vec === []) return;
        $this->vectorIndex->add($memory->id, $vec, [
            'type'  => $memory->type->value,
            'scope' => $memory->scope->value,
        ]);
    }

    /**
     * One-shot index population from current storage. Called lazily on
     * the first similarity query when the index is empty.
     */
    private function seedIndexFromStorage(): void
    {
        if ($this->embedder === null || $this->vectorIndex === null) return;
        $memories = $this->storage->loadAll();
        if ($memories === []) return;

        $texts = [];
        $rows  = [];
        foreach ($memories as $m) {
            $texts[] = trim($m->name . "\n" . $m->description . "\n" . $m->content);
            $rows[] = $m;
        }
        $vectors = $this->embedder->embed($texts);

        $items = [];
        foreach ($rows as $i => $m) {
            $vec = $vectors[$i] ?? [];
            if ($vec === []) continue;
            $items[] = new IndexedItem($m->id, $vec, [
                'type'  => $m->type->value,
                'scope' => $m->scope->value,
            ]);
        }
        $this->vectorIndex->addAll($items);
    }
    
    /**
     * Find relevant memories for a query
     */
    public function findRelevant(string $query, int $maxResults = 5): array
    {
        $headers = $this->storage->scan();
        
        if (empty($headers)) {
            return [];
        }
        
        // Use LLM to find relevant memories
        $relevantIds = $this->selectRelevantMemories($query, $headers, $maxResults);
        
        // Load the full memories
        $memories = [];
        foreach ($relevantIds as $id) {
            $memory = $this->storage->load($id);
            if ($memory !== null) {
                $memory->markAccessed();
                $this->storage->save($memory);
                $memories[] = $memory;
            }
        }
        
        return $memories;
    }
    
    /**
     * Use LLM to select relevant memories
     */
    private function selectRelevantMemories(string $query, array $headers, int $maxResults): array
    {
        $manifest = $this->formatMemoryManifest($headers);
        
        $prompt = <<<PROMPT
Given the user's query and a list of available memories, select up to {$maxResults} most relevant memories.

USER QUERY:
{$query}

AVAILABLE MEMORIES:
{$manifest}

Return only the filenames (without .md extension) of the most relevant memories, one per line.
If no memories are relevant, return "NONE".
PROMPT;
        
        $response = $this->provider->generateResponse(
            messages: [
                ['role' => 'system', 'content' => 'You are a memory relevance matcher. Select the most relevant memories for the given query.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            options: [
                'temperature' => 0.1,
                'max_tokens' => 200,
            ],
        );
        
        return $this->parseRelevantMemories($response->content);
    }
    
    /**
     * Format memory manifest for LLM
     */
    private function formatMemoryManifest(array $headers): string
    {
        $lines = [];
        
        foreach ($headers as $header) {
            $filename = str_replace('.md', '', $header['filename']);
            $type = $header['type'] ?? 'unknown';
            $desc = $header['description'] ?? 'No description';
            $name = $header['name'] ?? $filename;
            
            $lines[] = "- {$filename} [{$type}]: {$name} - {$desc}";
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Parse relevant memory IDs from response
     */
    private function parseRelevantMemories(string $response): array
    {
        if (str_contains(strtoupper($response), 'NONE')) {
            return [];
        }
        
        $lines = explode("\n", $response);
        $ids = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Remove any list markers
            $line = preg_replace('/^[-*•]\s*/', '', $line);
            
            // Remove .md extension if present
            $line = str_replace('.md', '', $line);
            
            // Clean up the ID
            $line = trim($line);
            
            if (!empty($line)) {
                $ids[] = $line;
            }
        }
        
        return $ids;
    }
    
    /**
     * Search memories by keyword
     */
    public function search(string $keyword): array
    {
        $allMemories = $this->storage->loadAll();
        $results = [];
        
        $keyword = strtolower($keyword);
        
        foreach ($allMemories as $memory) {
            $searchText = strtolower(
                $memory->name . ' ' . 
                $memory->description . ' ' . 
                $memory->content
            );
            
            if (str_contains($searchText, $keyword)) {
                $results[] = $memory;
            }
        }
        
        return $results;
    }
    
    /**
     * Get recent memories
     */
    public function getRecent(int $limit = 10): array
    {
        $memories = $this->storage->loadAll();
        
        return array_slice($memories, 0, $limit);
    }
    
    /**
     * Get memories by type
     */
    public function getByType(MemoryType $type, int $limit = null): array
    {
        $memories = $this->storage->findByType($type);
        
        if ($limit !== null) {
            $memories = array_slice($memories, 0, $limit);
        }
        
        return $memories;
    }
    
    /**
     * Get stale memories that haven't been accessed recently
     */
    public function getStaleMemories(int $staleDays = 30): array
    {
        $memories = $this->storage->loadAll();
        $stale = [];
        
        foreach ($memories as $memory) {
            if ($memory->isStale($staleDays)) {
                $stale[] = $memory;
            }
        }
        
        return $stale;
    }
}