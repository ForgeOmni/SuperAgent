<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use SuperAgent\Context\Message;
use SuperAgent\LLM\ProviderInterface;
use SuperAgent\Memory\Storage\MemoryStorageInterface;

class MemoryExtractor
{
    public function __construct(
        private MemoryStorageInterface $storage,
        private ProviderInterface $provider,
        private MemoryConfig $config,
    ) {}
    
    /**
     * Extract memories from a conversation
     */
    public function extractFromConversation(array $messages): array
    {
        if (!$this->shouldExtract($messages)) {
            return [];
        }
        
        $prompt = $this->buildExtractionPrompt($messages);
        
        $response = $this->provider->generateResponse(
            messages: [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $prompt],
            ],
            options: [
                'temperature' => 0.3,
                'max_tokens' => 2000,
            ],
        );
        
        return $this->parseExtractedMemories($response->content);
    }
    
    /**
     * Check if we should extract memories
     */
    private function shouldExtract(array $messages): bool
    {
        // Calculate token count
        $tokenCount = 0;
        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $content = is_string($message->content) ? $message->content : json_encode($message->content);
                $tokenCount += (int) (strlen($content) / 4); // Rough estimate
            }
        }
        
        return $tokenCount >= $this->config->minimumTokensBetweenUpdate;
    }
    
    /**
     * Build the extraction prompt
     */
    private function buildExtractionPrompt(array $messages): string
    {
        $conversation = [];
        
        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $role = ucfirst($message->role->value);
                $content = is_string($message->content) ? $message->content : json_encode($message->content);
                $conversation[] = "{$role}: {$content}";
            }
        }
        
        $conversationText = implode("\n\n", $conversation);
        
        return <<<PROMPT
Extract important information from the following conversation that should be remembered for future sessions.

CONVERSATION:
{$conversationText}

Focus on extracting:
1. Information about the user (role, expertise, preferences)
2. Feedback and corrections (what to do/avoid)
3. Project context (ongoing work, goals, decisions)
4. References to external resources

Format each memory as:
TYPE: [user|feedback|project|reference]
NAME: [short descriptive name]
DESCRIPTION: [one-line description]
CONTENT: [the actual memory content]
---

For feedback and project memories, include "Why:" and "How to apply:" sections.
PROMPT;
    }
    
    /**
     * Get the system prompt for extraction
     */
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a memory extraction assistant. Your job is to identify and extract important information from conversations that should be remembered across sessions.

Memory Types:
- user: Information about the user's role, goals, responsibilities, knowledge
- feedback: Guidance about how to approach work (corrections, confirmations)
- project: Ongoing work, goals, initiatives not derivable from code
- reference: Pointers to external systems and resources

Guidelines:
- Only extract information that is NOT derivable from the current project state
- Convert relative dates to absolute dates
- For feedback/project memories, always include "Why:" and "How to apply:" sections
- Be selective - only extract truly important information
- Prefer team scope for project/reference memories
PROMPT;
    }
    
    /**
     * Parse extracted memories from LLM response
     */
    private function parseExtractedMemories(string $response): array
    {
        $memories = [];
        $blocks = explode('---', $response);
        
        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }
            
            $memory = $this->parseMemoryBlock($block);
            if ($memory !== null) {
                $memories[] = $memory;
            }
        }
        
        return $memories;
    }
    
    /**
     * Parse a single memory block
     */
    private function parseMemoryBlock(string $block): ?Memory
    {
        $lines = explode("\n", $block);
        $data = [];
        $content = [];
        $inContent = false;
        
        foreach ($lines as $line) {
            if (!$inContent) {
                if (str_starts_with($line, 'TYPE:')) {
                    $data['type'] = trim(substr($line, 5));
                } elseif (str_starts_with($line, 'NAME:')) {
                    $data['name'] = trim(substr($line, 5));
                } elseif (str_starts_with($line, 'DESCRIPTION:')) {
                    $data['description'] = trim(substr($line, 12));
                } elseif (str_starts_with($line, 'CONTENT:')) {
                    $inContent = true;
                    $firstContent = trim(substr($line, 8));
                    if (!empty($firstContent)) {
                        $content[] = $firstContent;
                    }
                }
            } else {
                $content[] = $line;
            }
        }
        
        if (empty($data['type']) || empty($data['name']) || empty($content)) {
            return null;
        }
        
        try {
            $type = MemoryType::from($data['type']);
        } catch (\ValueError $e) {
            return null;
        }
        
        $id = $this->generateMemoryId($data['name']);
        
        return new Memory(
            id: $id,
            name: $data['name'],
            description: $data['description'] ?? '',
            type: $type,
            content: trim(implode("\n", $content)),
            scope: $type->getDefaultScope(),
        );
    }
    
    /**
     * Generate a memory ID from name
     */
    private function generateMemoryId(string $name): string
    {
        $id = strtolower(str_replace(' ', '_', $name));
        $id = preg_replace('/[^a-z0-9_-]/', '', $id);
        
        return $id ?: 'memory_' . uniqid();
    }
    
    /**
     * Extract and save memories from conversation
     */
    public function extractAndSave(array $messages): array
    {
        $extracted = $this->extractFromConversation($messages);
        $saved = [];
        
        foreach ($extracted as $memory) {
            // Check if similar memory exists
            $existing = $this->findSimilarMemory($memory);
            
            if ($existing !== null) {
                // Update existing memory
                $updated = $existing->update(
                    content: $this->mergeContent($existing->content, $memory->content),
                    description: $memory->description,
                );
                $this->storage->save($updated);
                $saved[] = $updated;
            } else {
                // Save new memory
                $this->storage->save($memory);
                $saved[] = $memory;
            }
        }
        
        return $saved;
    }
    
    /**
     * Find similar existing memory
     */
    private function findSimilarMemory(Memory $memory): ?Memory
    {
        $existing = $this->storage->findByType($memory->type);
        
        foreach ($existing as $existingMemory) {
            // Simple similarity check based on name
            $similarity = similar_text(
                strtolower($memory->name),
                strtolower($existingMemory->name),
                $percent
            );
            
            if ($percent > 80) {
                return $existingMemory;
            }
        }
        
        return null;
    }
    
    /**
     * Merge memory content
     */
    private function mergeContent(string $existing, string $new): string
    {
        // Simple merge - append new content if different
        if ($existing === $new) {
            return $existing;
        }
        
        // Check if new content is already contained
        if (str_contains($existing, $new)) {
            return $existing;
        }
        
        return $existing . "\n\n---\n\n" . $new;
    }
}