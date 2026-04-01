<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\LLM\ProviderInterface;
use SuperAgent\Memory\Storage\MemoryStorageInterface;

class AutoDreamConsolidator
{
    private const LOCK_KEY = 'memory_autodream_lock';
    private const LAST_RUN_KEY = 'memory_autodream_last_run';
    private const SESSION_COUNT_KEY = 'memory_autodream_session_count';
    private const SCAN_THROTTLE_KEY = 'memory_autodream_scan_throttle';
    
    private SimpleCache $cache;
    
    public function __construct(
        private MemoryStorageInterface $storage,
        private ProviderInterface $provider,
        private MemoryConfig $config,
        private LoggerInterface $logger = new NullLogger(),
        ?SimpleCache $cache = null,
    ) {
        $this->cache = $cache ?? new SimpleCache($config->getBasePath(getcwd()));
    }
    
    /**
     * Check if auto-dream should run
     */
    public function shouldRun(): bool
    {
        // Check conditions without updating throttle
        return $this->checkConditions(false);
    }
    
    /**
     * Check all conditions for running auto-dream
     */
    private function checkConditions(bool $updateThrottle = true): bool
    {
        // Check scan throttle
        $lastScan = $this->cache->get(self::SCAN_THROTTLE_KEY);
        if ($lastScan !== null) {
            $minutesSince = Carbon::parse($lastScan)->diffInMinutes(Carbon::now());
            if ($minutesSince < $this->config->autoDreamScanThrottleMinutes) {
                return false;
            }
        }
        
        // Update scan throttle only if requested
        if ($updateThrottle) {
            $this->cache->put(self::SCAN_THROTTLE_KEY, Carbon::now(), 60 * 60);
        }
        
        // Check time gate
        $lastRun = $this->cache->get(self::LAST_RUN_KEY);
        if ($lastRun !== null) {
            $hoursSince = Carbon::parse($lastRun)->diffInHours(Carbon::now());
            if ($hoursSince < $this->config->autoDreamMinHours) {
                $this->logger->debug('AutoDream time gate not met', [
                    'hours_since' => $hoursSince,
                    'required' => $this->config->autoDreamMinHours,
                ]);
                return false;
            }
        }
        
        // Check session gate
        $sessionCount = $this->cache->get(self::SESSION_COUNT_KEY, 0);
        if ($sessionCount < $this->config->autoDreamMinSessions) {
            $this->logger->debug('AutoDream session gate not met', [
                'session_count' => $sessionCount,
                'required' => $this->config->autoDreamMinSessions,
            ]);
            return false;
        }
        
        // Check lock
        if ($this->isLocked()) {
            $this->logger->debug('AutoDream is already running');
            return false;
        }
        
        return true;
    }
    
    /**
     * Run the auto-dream consolidation process
     */
    public function run(): bool
    {
        if (!$this->checkConditions(false)) {
            return false;
        }
        
        // Update scan throttle now that we're actually running
        $this->cache->put(self::SCAN_THROTTLE_KEY, Carbon::now(), 60 * 60);
        
        // Acquire lock
        if (!$this->acquireLock()) {
            return false;
        }
        
        try {
            $this->logger->info('Starting AutoDream consolidation');
            
            // Phase 1: Orient
            $existingMemories = $this->orient();
            
            // Phase 2: Gather
            $newInfo = $this->gather();
            
            // Phase 3: Consolidate
            $consolidated = $this->consolidate($existingMemories, $newInfo);
            
            // Phase 4: Prune
            $this->prune($consolidated);
            
            // Update last run time and reset session count
            $this->cache->put(self::LAST_RUN_KEY, Carbon::now(), 60 * 60 * 24 * 7);
            $this->cache->put(self::SESSION_COUNT_KEY, 0, 60 * 60 * 24 * 7);
            
            $this->logger->info('AutoDream consolidation completed', [
                'memories_processed' => count($consolidated),
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('AutoDream consolidation failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            $this->releaseLock();
        }
    }
    
    /**
     * Phase 1: Orient - Read existing memories and index
     */
    private function orient(): array
    {
        $this->logger->debug('AutoDream Phase 1: Orient');
        
        return $this->storage->loadAll();
    }
    
    /**
     * Phase 2: Gather - Look for new information
     */
    private function gather(): array
    {
        $this->logger->debug('AutoDream Phase 2: Gather');
        
        $newInfo = [];
        
        // Get recent daily logs
        $logs = $this->storage->getDailyLogs(7);
        
        foreach ($logs as $log) {
            $extracted = $this->extractFromLog($log['content']);
            $newInfo = array_merge($newInfo, $extracted);
        }
        
        return $newInfo;
    }
    
    /**
     * Phase 3: Consolidate - Write/update memory files
     */
    private function consolidate(array $existing, array $newInfo): array
    {
        $this->logger->debug('AutoDream Phase 3: Consolidate');
        
        $consolidated = $existing;
        
        foreach ($newInfo as $info) {
            $merged = false;
            
            // Try to merge with existing memory
            foreach ($consolidated as $key => $memory) {
                if ($this->shouldMerge($memory, $info)) {
                    $consolidated[$key] = $this->mergeMemories($memory, $info);
                    $merged = true;
                    break;
                }
            }
            
            // Add as new memory if not merged
            if (!$merged) {
                $consolidated[] = $info;
            }
        }
        
        // Save all memories
        foreach ($consolidated as $memory) {
            $this->storage->save($memory);
        }
        
        return $consolidated;
    }
    
    /**
     * Phase 4: Prune - Update index and remove stale entries
     */
    private function prune(array $memories): void
    {
        $this->logger->debug('AutoDream Phase 4: Prune');
        
        // Remove expired memories
        $cutoffDate = Carbon::now()->subDays($this->config->expireMemoryDays);
        
        foreach ($memories as $memory) {
            if ($memory->createdAt < $cutoffDate && $memory->isStale($this->config->expireMemoryDays)) {
                $this->logger->info('Removing expired memory', [
                    'id' => $memory->id,
                    'age_days' => $memory->getAgeInDays(),
                ]);
                $this->storage->delete($memory->id);
            }
        }
        
        // Resolve contradictions
        $this->resolveContradictions($memories);
        
        // Update index
        $this->storage->updateIndex();
    }
    
    /**
     * Extract information from a daily log
     */
    private function extractFromLog(string $content): array
    {
        $prompt = <<<PROMPT
Extract any important information from this daily log that should be consolidated into long-term memory.

LOG CONTENT:
{$content}

Focus on:
- User preferences and feedback
- Project decisions and context
- External references
- Patterns that should be remembered

Return extracted memories in the format:
TYPE: [user|feedback|project|reference]
NAME: [short name]
CONTENT: [memory content]
---
PROMPT;
        
        $response = $this->provider->generateResponse(
            messages: [
                ['role' => 'system', 'content' => 'You are consolidating daily logs into long-term memories.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            options: [
                'temperature' => 0.3,
                'max_tokens' => 1000,
            ],
        );
        
        return $this->parseMemories($response->content);
    }
    
    /**
     * Parse memories from response
     */
    private function parseMemories(string $response): array
    {
        $memories = [];
        $blocks = explode('---', $response);
        
        foreach ($blocks as $block) {
            $memory = $this->parseMemoryBlock($block);
            if ($memory !== null) {
                $memories[] = $memory;
            }
        }
        
        return $memories;
    }
    
    /**
     * Parse a memory block
     */
    private function parseMemoryBlock(string $block): ?Memory
    {
        $lines = explode("\n", trim($block));
        $data = [];
        
        foreach ($lines as $line) {
            if (str_starts_with($line, 'TYPE:')) {
                $data['type'] = trim(substr($line, 5));
            } elseif (str_starts_with($line, 'NAME:')) {
                $data['name'] = trim(substr($line, 5));
            } elseif (str_starts_with($line, 'CONTENT:')) {
                $data['content'] = trim(substr($line, 8));
            }
        }
        
        if (empty($data['type']) || empty($data['name']) || empty($data['content'])) {
            return null;
        }
        
        try {
            $type = MemoryType::from($data['type']);
        } catch (\ValueError $e) {
            return null;
        }
        
        return new Memory(
            id: $this->generateId($data['name']),
            name: $data['name'],
            description: substr($data['content'], 0, 100),
            type: $type,
            content: $data['content'],
        );
    }
    
    /**
     * Check if two memories should be merged
     */
    private function shouldMerge(Memory $existing, Memory $new): bool
    {
        // Same type and similar name
        if ($existing->type !== $new->type) {
            return false;
        }
        
        similar_text(
            strtolower($existing->name),
            strtolower($new->name),
            $percent
        );
        
        return $percent > 70;
    }
    
    /**
     * Merge two memories
     */
    private function mergeMemories(Memory $existing, Memory $new): Memory
    {
        // Combine content if different
        $content = $existing->content;
        
        if (!str_contains($content, $new->content)) {
            $content .= "\n\n---\n\n" . $new->content;
        }
        
        return $existing->update($content);
    }
    
    /**
     * Resolve contradictions between memories
     */
    private function resolveContradictions(array $memories): void
    {
        // Group by type
        $byType = [];
        foreach ($memories as $memory) {
            $byType[$memory->type->value][] = $memory;
        }
        
        // Look for potential contradictions within each type
        foreach ($byType as $type => $typeMemories) {
            if (count($typeMemories) < 2) {
                continue;
            }
            
            // This is a simplified contradiction detection
            // In production, you'd use LLM to identify actual contradictions
            $this->detectAndResolveContradictions($typeMemories);
        }
    }
    
    /**
     * Detect and resolve contradictions in a set of memories
     */
    private function detectAndResolveContradictions(array $memories): void
    {
        // Simplified: just log potential issues
        // In production, use LLM to identify and resolve
        foreach ($memories as $i => $memory1) {
            foreach ($memories as $j => $memory2) {
                if ($i >= $j) continue;
                
                similar_text(
                    strtolower($memory1->name),
                    strtolower($memory2->name),
                    $percent
                );
                
                if ($percent > 50 && $percent < 90) {
                    $this->logger->warning('Potential contradiction detected', [
                        'memory1' => $memory1->id,
                        'memory2' => $memory2->id,
                        'similarity' => $percent,
                    ]);
                }
            }
        }
    }
    
    /**
     * Generate memory ID
     */
    private function generateId(string $name): string
    {
        $id = strtolower(str_replace(' ', '_', $name));
        $id = preg_replace('/[^a-z0-9_-]/', '', $id);
        
        return $id ?: 'memory_' . uniqid();
    }
    
    /**
     * Increment session count
     */
    public function incrementSessionCount(): void
    {
        $count = $this->cache->get(self::SESSION_COUNT_KEY, 0);
        $this->cache->put(self::SESSION_COUNT_KEY, $count + 1, 60 * 60 * 24 * 7);
    }
    
    /**
     * Check if consolidation is locked
     */
    private function isLocked(): bool
    {
        return $this->cache->has(self::LOCK_KEY);
    }
    
    /**
     * Acquire consolidation lock
     */
    private function acquireLock(): bool
    {
        return $this->cache->add(self::LOCK_KEY, true, 60 * 30); // 30 minute lock
    }
    
    /**
     * Release consolidation lock
     */
    private function releaseLock(): void
    {
        $this->cache->forget(self::LOCK_KEY);
    }
}