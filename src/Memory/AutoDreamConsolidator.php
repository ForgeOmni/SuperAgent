<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use Carbon\Carbon;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\LLM\ProviderInterface;
use SuperAgent\Memory\Storage\MemoryStorageInterface;
use SuperAgent\Memory\DailyLog;

class AutoDreamConsolidator
{
    private const LOCK_KEY = 'memory_autodream_lock';
    private const LAST_RUN_KEY = 'memory_autodream_last_run';
    private const SESSION_COUNT_KEY = 'memory_autodream_session_count';
    private const SCAN_THROTTLE_KEY = 'memory_autodream_scan_throttle';
    
    private SimpleCache $cache;
    private ?DailyLog $dailyLog = null;

    /** File-based consolidation lock (mtime IS lastConsolidatedAt) */
    private ?string $lockFile = null;

    public function __construct(
        private MemoryStorageInterface $storage,
        private ProviderInterface $provider,
        private MemoryConfig $config,
        private LoggerInterface $logger = new NullLogger(),
        ?SimpleCache $cache = null,
        ?DailyLog $dailyLog = null,
    ) {
        $basePath = $config->getBasePath(getcwd());
        $this->cache = $cache ?? new SimpleCache($basePath);
        $this->dailyLog = $dailyLog ?? new DailyLog($basePath, $logger);
        $this->lockFile = $basePath . '/.consolidate-lock';
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
     * Phase 2: Gather - Look for new information from KAIROS daily logs.
     * Priority: daily logs > existing memories that drifted > transcript search
     */
    private function gather(): array
    {
        $this->logger->debug('AutoDream Phase 2: Gather');

        $newInfo = [];

        // Primary source: KAIROS daily logs since last consolidation
        $lastRun = $this->cache->get(self::LAST_RUN_KEY);
        $since = $lastRun ? Carbon::parse($lastRun) : Carbon::now()->subDays(7);

        $dailyLogs = $this->dailyLog->getLogsSince($since);

        $maxGatherEntries = 500; // Prevent unbounded memory growth
        foreach ($dailyLogs as $date => $content) {
            if (empty(trim($content))) {
                continue;
            }
            $extracted = $this->extractFromLog($content);
            $newInfo = array_merge($newInfo, $extracted);
            if (count($newInfo) >= $maxGatherEntries) {
                $this->logger->warning('AutoDream gather phase hit memory limit', [
                    'limit' => $maxGatherEntries,
                ]);
                break;
            }
            $this->logger->debug("Extracted from daily log {$date}", [
                'entries_found' => count($extracted),
            ]);
        }

        // Fallback: storage daily logs (legacy format)
        if (empty($newInfo)) {
            $legacyLogs = $this->storage->getDailyLogs(7);
            foreach ($legacyLogs as $log) {
                $extracted = $this->extractFromLog($log['content'] ?? '');
                $newInfo = array_merge($newInfo, $extracted);
            }
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
            
            // Add as new memory if not merged (with limit)
            if (!$merged) {
                if (count($consolidated) < 1000) {
                    $consolidated[] = $info;
                } else {
                    $this->logger->warning('AutoDream consolidation hit memory limit', [
                        'limit' => 1000,
                        'skipped' => $info->name ?? 'unknown',
                    ]);
                }
            }
        }
        
        // Save all memories
        foreach ($consolidated as $memory) {
            $this->storage->save($memory);
        }
        
        return $consolidated;
    }
    
    /**
     * Phase 4: Prune - Update MEMORY.md index and remove stale entries.
     * Enforces: < 200 lines, < 25KB for MEMORY.md
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

        // Update index with size enforcement
        $this->storage->updateIndex();

        // Enforce MEMORY.md size limits
        $this->enforceEntrypointLimits();
    }

    /**
     * Enforce MEMORY.md size limits (< 200 lines, < 25KB).
     */
    private function enforceEntrypointLimits(): void
    {
        $basePath = $this->config->getBasePath(getcwd());
        $entrypoint = $basePath . '/MEMORY.md';

        if (!file_exists($entrypoint)) {
            return;
        }

        $content = file_get_contents($entrypoint);
        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $bytes = strlen($content);
        $modified = false;

        // Enforce line limit
        if (count($lines) > $this->config->maxEntrypointLines) {
            $lines = array_slice($lines, 0, $this->config->maxEntrypointLines);
            $lines[] = '';
            $lines[] = '<!-- Truncated: MEMORY.md exceeded ' . $this->config->maxEntrypointLines . ' lines -->';
            $modified = true;
        }

        // Enforce byte limit
        $content = implode("\n", $lines);
        if (strlen($content) > $this->config->maxEntrypointBytes) {
            $content = substr($content, 0, $this->config->maxEntrypointBytes);
            $content .= "\n\n<!-- Truncated: MEMORY.md exceeded " . $this->config->maxEntrypointBytes . " bytes -->";
            $modified = true;
        }

        if ($modified) {
            file_put_contents($entrypoint, $content);
            $this->logger->info('MEMORY.md truncated to fit size limits');
        }
    }
    
    /**
     * Get the 4-phase consolidation prompt (from CC auto-dream).
     */
    public function getConsolidationPrompt(string $memoryDir): string
    {
        return <<<PROMPT
## Phase 1 — Orient
- Read MEMORY.md (entrypoint index)
- Skim existing topic files
- Review logs/ subdirectory for daily entries

## Phase 2 — Gather recent signal
Priority order:
1. Daily logs (logs/YYYY/MM/YYYY-MM-DD.md) — primary source
2. Existing memories that have drifted from current state
3. Codebase contradictions (facts in memory that code disproves)

## Phase 3 — Consolidate
- Merge new signal into existing topic files
- Convert relative dates to absolute (e.g., "yesterday" → "2026-03-31")
- Delete contradicted facts
- Create new topic files for genuinely new subjects

## Phase 4 — Prune and index
- Update MEMORY.md: < {$this->config->maxEntrypointLines} lines, < {$this->config->maxEntrypointBytes} bytes
- Format: - [Title](file.md) — one-line hook (~150 chars)
- Remove stale pointers, demote verbose entries
- Resolve contradictions (newer wins unless explicitly overridden)
PROMPT;
    }

    /**
     * Extract information from a daily log using the 4-phase prompt.
     */
    private function extractFromLog(string $content): array
    {
        $prompt = <<<PROMPT
Extract important information from this daily log that should be consolidated into long-term memory.

LOG CONTENT:
{$content}

Priority:
- User corrections and preferences (feedback type)
- Project decisions and context (project type)
- Facts about user's role and goals (user type)
- Pointers to external systems (reference type)
- Convert relative dates to absolute dates
- Skip ephemeral details (in-progress state, debugging steps)

Return extracted memories in the format:
TYPE: [user|feedback|project|reference]
NAME: [short name]
CONTENT: [memory content with Why: and How to apply: for feedback/project types]
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