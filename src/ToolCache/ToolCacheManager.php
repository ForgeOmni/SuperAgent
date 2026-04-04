<?php

namespace SuperAgent\ToolCache;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Tools\ToolResult;

class ToolCacheManager
{
    private CacheInterface $cache;
    private array $config;
    private LoggerInterface $logger;
    private CacheKeyStrategy $keyStrategy;
    private CacheStatistics $statistics;
    
    public function __construct(
        CacheInterface $cache,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();
        $this->keyStrategy = new CacheKeyStrategy($this->config['key_strategy'] ?? 'hash');
        $this->statistics = new CacheStatistics();
    }
    
    /**
     * Get cached result or execute tool
     */
    public function getOrExecute(
        string $toolName,
        array $params,
        callable $executor
    ): ToolResult {
        // Check if tool is cacheable
        if (!$this->isCacheable($toolName)) {
            $this->statistics->recordMiss($toolName, 'not_cacheable');
            return $executor();
        }
        
        // Generate cache key
        $cacheKey = $this->keyStrategy->generateKey($toolName, $params);
        
        // Check for cached result
        $cached = $this->getFromCache($cacheKey, $toolName);
        if ($cached !== null) {
            $this->statistics->recordHit($toolName);
            $this->logger->info('Tool cache hit', [
                'tool' => $toolName,
                'key' => $cacheKey,
            ]);
            return $cached;
        }
        
        // Execute tool
        $this->statistics->recordMiss($toolName, 'cache_miss');
        $result = $executor();
        
        // Cache successful results
        if (!$result->isError && $this->shouldCache($toolName, $params, $result)) {
            $this->saveToCache($cacheKey, $toolName, $result);
        }
        
        return $result;
    }
    
    /**
     * Check if tool is cacheable
     */
    public function isCacheable(string $toolName): bool
    {
        // Check whitelist
        if (!empty($this->config['cacheable_tools'])) {
            return in_array($toolName, $this->config['cacheable_tools']);
        }
        
        // Check blacklist
        if (!empty($this->config['non_cacheable_tools'])) {
            return !in_array($toolName, $this->config['non_cacheable_tools']);
        }
        
        // Default behavior
        return $this->isReadOnlyTool($toolName);
    }
    
    /**
     * Determine if tool is read-only (safe to cache)
     */
    private function isReadOnlyTool(string $toolName): bool
    {
        $readOnlyTools = [
            'read_file',
            'file_read',
            'glob',
            'grep',
            'search',
            'list_files',
            'get_file_info',
            'tool_search',
        ];
        
        // Check exact match or pattern
        foreach ($readOnlyTools as $pattern) {
            if ($toolName === $pattern || str_contains(strtolower($toolName), $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if result should be cached
     */
    private function shouldCache(string $toolName, array $params, ToolResult $result): bool
    {
        // Don't cache empty results if configured
        if (!$this->config['cache_empty_results'] && empty($result->content)) {
            return false;
        }
        
        // Don't cache large results
        $resultSize = strlen(serialize($result));
        if ($resultSize > $this->config['max_cache_size']) {
            $this->logger->warning('Result too large to cache', [
                'tool' => $toolName,
                'size' => $resultSize,
                'max' => $this->config['max_cache_size'],
            ]);
            return false;
        }
        
        // Check custom conditions
        if (isset($this->config['cache_condition']) && is_callable($this->config['cache_condition'])) {
            return call_user_func($this->config['cache_condition'], $toolName, $params, $result);
        }
        
        return true;
    }
    
    /**
     * Get from cache
     */
    private function getFromCache(string $key, string $toolName): ?ToolResult
    {
        try {
            $cached = $this->cache->get($key);
            
            if ($cached === null) {
                return null;
            }
            
            // Validate cached data
            if (!$this->isValidCachedResult($cached)) {
                $this->logger->warning('Invalid cached data', [
                    'tool' => $toolName,
                    'key' => $key,
                ]);
                $this->cache->delete($key);
                return null;
            }
            
            // Check if cache is stale
            if ($this->isStale($cached)) {
                $this->cache->delete($key);
                return null;
            }
            
            // Deserialize and return
            return $this->deserializeResult($cached);
            
        } catch (\Exception $e) {
            $this->logger->error('Cache read error', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Save to cache
     */
    private function saveToCache(string $key, string $toolName, ToolResult $result): void
    {
        try {
            $data = $this->serializeResult($result);
            $ttl = $this->getTTL($toolName);
            
            $this->cache->set($key, $data, $ttl);
            
            $this->logger->debug('Cached tool result', [
                'tool' => $toolName,
                'key' => $key,
                'ttl' => $ttl,
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Cache write error', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get TTL for tool
     */
    private function getTTL(string $toolName): int
    {
        // Check tool-specific TTL
        if (isset($this->config['tool_ttl'][$toolName])) {
            return $this->config['tool_ttl'][$toolName];
        }
        
        // Use default TTL
        return $this->config['default_ttl'] ?? 300;
    }
    
    /**
     * Check if cached data is stale
     */
    private function isStale(array $cached): bool
    {
        if (!isset($cached['timestamp'])) {
            return true;
        }
        
        $age = time() - $cached['timestamp'];
        $maxAge = $this->config['max_stale_age'] ?? 86400; // 24 hours
        
        return $age > $maxAge;
    }
    
    /**
     * Validate cached result structure
     */
    private function isValidCachedResult($cached): bool
    {
        return is_array($cached) &&
               isset($cached['result']) &&
               isset($cached['timestamp']) &&
               isset($cached['version']);
    }
    
    /**
     * Serialize result for caching
     */
    private function serializeResult(ToolResult $result): array
    {
        return [
            'result' => [
                'content' => $result->content,
                'isError' => $result->isError,
            ],
            'timestamp' => time(),
            'version' => $this->config['cache_version'] ?? '1.0',
        ];
    }
    
    /**
     * Deserialize cached result
     */
    private function deserializeResult(array $cached): ToolResult
    {
        $data = $cached['result'];
        return new ToolResult(
            content: $data['content'],
            isError: $data['isError'] ?? false
        );
    }
    
    /**
     * Clear cache for specific tool or all
     */
    public function clear(?string $toolName = null): void
    {
        if ($toolName === null) {
            $this->cache->clear();
            $this->logger->info('Cleared all tool cache');
        } else {
            // Clear by prefix (requires cache implementation support)
            $pattern = $this->keyStrategy->getPattern($toolName);
            $this->clearByPattern($pattern);
            $this->logger->info('Cleared cache for tool', ['tool' => $toolName]);
        }
    }
    
    /**
     * Clear cache by pattern
     */
    private function clearByPattern(string $pattern): void
    {
        // This depends on cache implementation
        // For Redis/Memcached, we can use pattern deletion
        // For file cache, we need to iterate
        
        // Simplified implementation - clear all
        // In production, use cache adapter that supports pattern deletion
        $this->cache->clear();
    }
    
    /**
     * Warm up cache with predefined data
     */
    public function warmUp(array $data): void
    {
        foreach ($data as $item) {
            $key = $this->keyStrategy->generateKey($item['tool'], $item['params']);
            $result = new ToolResult(
                content: $item['result'],
                isError: false
            );
            $this->saveToCache($key, $item['tool'], $result);
        }
        
        $this->logger->info('Cache warmed up', ['count' => count($data)]);
    }
    
    /**
     * Get cache statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics->toArray();
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'default_ttl' => 300, // 5 minutes
            'max_stale_age' => 86400, // 24 hours
            'max_cache_size' => 1048576, // 1MB
            'cache_empty_results' => false,
            'cache_version' => '1.0',
            'key_strategy' => 'hash',
            'cacheable_tools' => [
                'read_file',
                'grep',
                'glob',
                'search',
                'list_files',
            ],
            'non_cacheable_tools' => [
                'write_file',
                'edit_file',
                'delete_file',
                'bash',
                'execute',
            ],
            'tool_ttl' => [
                'read_file' => 600,    // 10 minutes
                'grep' => 300,         // 5 minutes
                'glob' => 300,         // 5 minutes
                'search' => 180,       // 3 minutes
            ],
        ];
    }
}