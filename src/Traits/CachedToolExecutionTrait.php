<?php

namespace SuperAgent\Traits;

use SuperAgent\ToolCache\ToolCacheManager;
use SuperAgent\ToolCache\Adapters\MemoryCacheAdapter;
use SuperAgent\ToolCache\Adapters\FileCacheAdapter;
use SuperAgent\Tools\ToolResult;
use Psr\SimpleCache\CacheInterface;

trait CachedToolExecutionTrait
{
    protected ?ToolCacheManager $toolCache = null;
    
    /**
     * Initialize tool cache
     */
    protected function initializeToolCache(array $config = []): void
    {
        if ($this->toolCache !== null) {
            return;
        }
        
        // Get cache adapter
        $adapter = $this->getCacheAdapter($config);
        
        // Create cache manager
        $this->toolCache = new ToolCacheManager(
            $adapter,
            $config,
            $this->logger ?? null
        );
    }
    
    /**
     * Execute tool with caching
     */
    protected function executeToolWithCache(
        string $toolName,
        array $params,
        callable $executor
    ): ToolResult {
        // Initialize cache if needed
        if ($this->toolCache === null) {
            $cacheConfig = $this->config['tool_cache'] ?? [];
            
            // Check if caching is enabled
            if (!($cacheConfig['enabled'] ?? true)) {
                return $executor();
            }
            
            $this->initializeToolCache($cacheConfig);
        }
        
        // Execute with cache
        return $this->toolCache->getOrExecute($toolName, $params, $executor);
    }
    
    /**
     * Get cache adapter based on configuration
     */
    protected function getCacheAdapter(array $config): CacheInterface
    {
        $driver = $config['driver'] ?? 'memory';
        
        return match ($driver) {
            'memory' => new MemoryCacheAdapter(),
            'file' => new FileCacheAdapter(
                $config['file_path'] ?? null,
                $config['default_ttl'] ?? 300
            ),
            'redis' => $this->getRedisAdapter($config),
            'custom' => $config['adapter'],
            default => new MemoryCacheAdapter(),
        };
    }
    
    /**
     * Get Redis cache adapter
     */
    protected function getRedisAdapter(array $config): CacheInterface
    {
        // Check if Laravel cache is available
        if (class_exists('\\Illuminate\\Support\\Facades\\Cache')) {
            return new class implements CacheInterface {
                public function get($key, $default = null): mixed
                {
                    return \\Illuminate\\Support\\Facades\\Cache::get($key, $default);
                }
                
                public function set($key, $value, $ttl = null): bool
                {
                    if ($ttl !== null) {
                        return \\Illuminate\\Support\\Facades\\Cache::put($key, $value, $ttl);
                    }
                    return \\Illuminate\\Support\\Facades\\Cache::forever($key, $value);
                }
                
                public function delete($key): bool
                {
                    return \\Illuminate\\Support\\Facades\\Cache::forget($key);
                }
                
                public function clear(): bool
                {
                    return \\Illuminate\\Support\\Facades\\Cache::flush();
                }
                
                public function getMultiple($keys, $default = null): iterable
                {
                    $result = [];
                    foreach ($keys as $key) {
                        $result[$key] = $this->get($key, $default);
                    }
                    return $result;
                }
                
                public function setMultiple($values, $ttl = null): bool
                {
                    foreach ($values as $key => $value) {
                        if (!$this->set($key, $value, $ttl)) {
                            return false;
                        }
                    }
                    return true;
                }
                
                public function deleteMultiple($keys): bool
                {
                    foreach ($keys as $key) {
                        $this->delete($key);
                    }
                    return true;
                }
                
                public function has($key): bool
                {
                    return \\Illuminate\\Support\\Facades\\Cache::has($key);
                }
            };
        }
        
        // Fall back to file cache
        return new FileCacheAdapter();
    }
    
    /**
     * Clear tool cache
     */
    protected function clearToolCache(?string $toolName = null): void
    {
        if ($this->toolCache !== null) {
            $this->toolCache->clear($toolName);
        }
    }
    
    /**
     * Get cache statistics
     */
    protected function getToolCacheStatistics(): array
    {
        if ($this->toolCache === null) {
            return [
                'enabled' => false,
                'message' => 'Tool cache not initialized',
            ];
        }
        
        return $this->toolCache->getStatistics();
    }
    
    /**
     * Warm up cache with predefined data
     */
    protected function warmUpToolCache(array $data): void
    {
        if ($this->toolCache !== null) {
            $this->toolCache->warmUp($data);
        }
    }
}