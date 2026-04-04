<?php

namespace SuperAgent\LazyContext;

use SuperAgent\Messages\Message;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 按需加载上下文管理器
 * 只在实际需要时加载相关上下文，减少内存和token使用
 */
class LazyContextManager
{
    private array $contextRegistry = [];
    private array $loadedContext = [];
    private array $contextMetadata = [];
    private ContextLoader $loader;
    private ContextSelector $selector;
    private ContextCache $cache;
    private LoggerInterface $logger;
    private array $config;
    private array $statistics = [
        'lazy_loads' => 0,
        'cache_hits' => 0,
        'tokens_saved' => 0,
        'memory_saved' => 0,
    ];
    
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();
        $this->loader = new ContextLoader($config);
        $this->selector = new ContextSelector($config);
        $this->cache = new ContextCache($config);
    }
    
    /**
     * 注册上下文片段（不实际加载）
     */
    public function registerContext(string $id, array $metadata): void
    {
        $this->contextRegistry[$id] = [
            'id' => $id,
            'type' => $metadata['type'] ?? 'general',
            'priority' => $metadata['priority'] ?? 5,
            'size' => $metadata['size'] ?? 0,
            'tags' => $metadata['tags'] ?? [],
            'dependencies' => $metadata['dependencies'] ?? [],
            'source' => $metadata['source'] ?? null,
            'data' => $metadata['data'] ?? null, // inline data payload
            'loaded' => false,
            'last_accessed' => null,
            'access_count' => 0,
        ];
        
        $this->logger->debug('Context registered', [
            'id' => $id,
            'type' => $metadata['type'] ?? 'general',
        ]);
    }
    
    /**
     * 根据当前任务获取相关上下文
     */
    public function getContextForTask(string $task, array $hints = []): array
    {
        // 使用选择器确定需要哪些上下文片段
        $requiredContextIds = $this->selector->selectForTask($task, $this->contextRegistry, $hints);
        
        $this->logger->info('Selected context for task', [
            'task' => $task,
            'selected_count' => count($requiredContextIds),
            'ids' => $requiredContextIds,
        ]);
        
        // 按需加载上下文
        $context = [];
        foreach ($requiredContextIds as $contextId) {
            $contextData = $this->loadContext($contextId);
            if ($contextData !== null) {
                $context = array_merge($context, $contextData);
            }
        }
        
        // 更新统计
        $this->updateStatistics($requiredContextIds, $context);
        
        return $context;
    }
    
    /**
     * 延迟加载特定上下文
     */
    public function loadContext(string $id): ?array
    {
        // 检查是否已加载
        if (isset($this->loadedContext[$id])) {
            $this->contextRegistry[$id]['access_count']++;
            $this->contextRegistry[$id]['last_accessed'] = time();
            $this->statistics['cache_hits']++;
            return $this->loadedContext[$id];
        }
        
        // 检查缓存
        $cached = $this->cache->get($id);
        if ($cached !== null) {
            $this->loadedContext[$id] = $cached;
            $this->contextRegistry[$id]['loaded'] = true;
            $this->statistics['cache_hits']++;
            return $cached;
        }
        
        // 实际加载
        if (!isset($this->contextRegistry[$id])) {
            $this->logger->warning('Context not registered', ['id' => $id]);
            return null;
        }
        
        $metadata = $this->contextRegistry[$id];
        
        // 加载依赖
        foreach ($metadata['dependencies'] as $depId) {
            $this->loadContext($depId);
        }
        
        // 使用加载器加载上下文
        $data = $this->loader->load($id, $metadata);
        
        if ($data === null) {
            $this->logger->error('Failed to load context', ['id' => $id]);
            return null;
        }
        
        // 存储加载的上下文
        $this->loadedContext[$id] = $data;
        $this->contextRegistry[$id]['loaded'] = true;
        $this->contextRegistry[$id]['access_count']++;
        $this->contextRegistry[$id]['last_accessed'] = time();
        
        // 缓存
        $this->cache->set($id, $data);
        
        $this->statistics['lazy_loads']++;
        
        $this->logger->debug('Context loaded', [
            'id' => $id,
            'size' => count($data),
        ]);
        
        // 自动清理旧的加载内容
        $this->autoCleanup();
        
        return $data;
    }
    
    /**
     * 预加载高优先级上下文
     */
    public function preloadPriority(int $minPriority = 8): void
    {
        foreach ($this->contextRegistry as $id => $metadata) {
            if ($metadata['priority'] >= $minPriority && !$metadata['loaded']) {
                $this->loadContext($id);
            }
        }
    }
    
    /**
     * 根据标签加载上下文
     */
    public function loadByTags(array $tags): array
    {
        $context = [];
        
        foreach ($this->contextRegistry as $id => $metadata) {
            if (array_intersect($tags, $metadata['tags'])) {
                $data = $this->loadContext($id);
                if ($data !== null) {
                    $context = array_merge($context, $data);
                }
            }
        }
        
        return $context;
    }
    
    /**
     * 卸载不常用的上下文以释放内存
     */
    public function unloadStale(int $maxAge = 300): void
    {
        $now = time();
        $unloaded = 0;
        
        foreach ($this->contextRegistry as $id => $metadata) {
            if (!$metadata['loaded']) {
                continue;
            }
            
            $age = $now - ($metadata['last_accessed'] ?? 0);
            
            if ($age > $maxAge && $metadata['priority'] < 7) {
                unset($this->loadedContext[$id]);
                $this->contextRegistry[$id]['loaded'] = false;
                $unloaded++;
            }
        }
        
        if ($unloaded > 0) {
            $this->logger->info('Unloaded stale context', ['count' => $unloaded]);
        }
    }
    
    /**
     * 获取智能上下文窗口
     */
    public function getSmartWindow(int $maxTokens, string $focusArea = null): array
    {
        // 根据焦点区域和令牌限制智能选择上下文
        $selected = $this->selector->selectByTokenLimit(
            $this->contextRegistry,
            $maxTokens,
            $focusArea
        );
        
        $window = [];
        $currentTokens = 0;
        
        foreach ($selected as $id) {
            $data = $this->loadContext($id);
            if ($data === null) {
                continue;
            }
            
            $tokens = $this->estimateTokens($data);
            if ($currentTokens + $tokens > $maxTokens) {
                break;
            }
            
            $window = array_merge($window, $data);
            $currentTokens += $tokens;
        }
        
        return $window;
    }
    
    /**
     * 获取上下文摘要（不加载完整内容）
     */
    public function getSummary(): array
    {
        $summary = [
            'total_registered' => count($this->contextRegistry),
            'total_loaded' => count($this->loadedContext),
            'by_type' => [],
            'by_priority' => [],
            'memory_usage' => 0,
        ];
        
        foreach ($this->contextRegistry as $metadata) {
            $type = $metadata['type'];
            $priority = $metadata['priority'];
            
            $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
            $summary['by_priority'][$priority] = ($summary['by_priority'][$priority] ?? 0) + 1;
            
            if ($metadata['loaded']) {
                $summary['memory_usage'] += $metadata['size'];
            }
        }
        
        return $summary;
    }
    
    /**
     * 自动清理策略
     */
    private function autoCleanup(): void
    {
        // 内存使用超过阈值时清理
        $memoryUsage = $this->getMemoryUsage();
        $maxMemory = $this->config['max_memory'] ?? 50 * 1024 * 1024; // 50MB
        
        if ($memoryUsage > $maxMemory) {
            $this->logger->warning('Memory limit exceeded, cleaning up', [
                'usage' => $memoryUsage,
                'limit' => $maxMemory,
            ]);
            
            // 清理最少使用的上下文
            $this->cleanupLRU();
        }
        
        // 定期清理过期内容
        if (rand(1, 100) <= 5) { // 5% 概率触发
            $this->unloadStale();
        }
    }
    
    /**
     * LRU 清理策略
     */
    private function cleanupLRU(): void
    {
        // 按访问时间和访问次数排序
        $contexts = $this->contextRegistry;
        uasort($contexts, function($a, $b) {
            if (!$a['loaded'] || !$b['loaded']) {
                return 0;
            }
            
            // 优先级高的保留
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] - $a['priority'];
            }
            
            // 访问次数多的保留
            if ($a['access_count'] !== $b['access_count']) {
                return $b['access_count'] - $a['access_count'];
            }
            
            // 最近访问的保留
            return ($b['last_accessed'] ?? 0) - ($a['last_accessed'] ?? 0);
        });
        
        // 清理后 50% 的内容
        $toClean = (int)(count($contexts) * 0.5);
        $cleaned = 0;
        
        foreach ($contexts as $id => $metadata) {
            if (!$metadata['loaded']) {
                continue;
            }
            
            if ($metadata['priority'] >= 8) { // 不清理高优先级
                continue;
            }
            
            unset($this->loadedContext[$id]);
            $this->contextRegistry[$id]['loaded'] = false;
            $cleaned++;
            
            if ($cleaned >= $toClean) {
                break;
            }
        }
        
        $this->logger->info('LRU cleanup completed', ['cleaned' => $cleaned]);
    }
    
    /**
     * 估算令牌数量
     */
    private function estimateTokens($data): int
    {
        if ($data instanceof Message) {
            return (int)(strlen($data->content ?? '') / 4);
        }
        
        if (is_array($data)) {
            $tokens = 0;
            foreach ($data as $item) {
                $tokens += $this->estimateTokens($item);
            }
            return $tokens;
        }
        
        return (int)(strlen(json_encode($data)) / 4);
    }
    
    /**
     * 获取内存使用
     */
    private function getMemoryUsage(): int
    {
        $usage = 0;
        foreach ($this->loadedContext as $data) {
            $usage += strlen(serialize($data));
        }
        return $usage;
    }
    
    /**
     * 更新统计信息
     */
    private function updateStatistics(array $selectedIds, array $loadedData): void
    {
        // 计算节省的令牌
        $fullSize = 0;
        foreach ($this->contextRegistry as $metadata) {
            $fullSize += $metadata['size'];
        }
        
        $actualSize = $this->estimateTokens($loadedData);
        $saved = $fullSize - $actualSize;
        
        $this->statistics['tokens_saved'] += max(0, $saved);
        $this->statistics['memory_saved'] += max(0, ($fullSize - $actualSize) * 4); // 估算字节
    }
    
    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        return array_merge($this->statistics, [
            'loaded_ratio' => count($this->loadedContext) / max(1, count($this->contextRegistry)),
            'cache_hit_rate' => $this->statistics['cache_hits'] / 
                max(1, $this->statistics['cache_hits'] + $this->statistics['lazy_loads']),
        ]);
    }
    
    /**
     * 清除所有加载的上下文
     */
    public function clear(): void
    {
        $this->loadedContext = [];
        foreach ($this->contextRegistry as $id => $metadata) {
            $this->contextRegistry[$id]['loaded'] = false;
        }
        $this->cache->clear();
        
        $this->logger->info('All loaded context cleared');
    }
    
    /**
     * 获取默认配置
     */
    private function getDefaultConfig(): array
    {
        return [
            'max_memory' => 50 * 1024 * 1024, // 50MB
            'cache_ttl' => 600, // 10 minutes
            'auto_cleanup' => true,
            'cleanup_threshold' => 0.8, // 80% memory usage
            'preload_priority' => 8, // 预加载优先级 >= 8 的内容
        ];
    }
}