<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use SuperAgent\LLM\ProviderInterface;
use SuperAgent\Memory\Storage\MemoryStorageInterface;

class MemoryRetriever
{
    public function __construct(
        private MemoryStorageInterface $storage,
        private ProviderInterface $provider,
        private MemoryConfig $config,
    ) {}
    
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