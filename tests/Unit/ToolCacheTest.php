<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\ToolCache\ToolCacheManager;
use SuperAgent\ToolCache\CacheKeyStrategy;
use SuperAgent\ToolCache\CacheStatistics;
use SuperAgent\ToolCache\Adapters\MemoryCacheAdapter;
use SuperAgent\ToolCache\Adapters\FileCacheAdapter;
use SuperAgent\Tools\ToolResult;

class ToolCacheTest extends TestCase
{
    private ToolCacheManager $cacheManager;
    private MemoryCacheAdapter $cache;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cache = new MemoryCacheAdapter();
        $this->cacheManager = new ToolCacheManager($this->cache, [
            'cacheable_tools' => ['read_file', 'grep', 'search'],
            'non_cacheable_tools' => ['write_file', 'bash'],
            'default_ttl' => 300,
        ]);
    }
    
    /**
     * Test cache hit
     */
    public function testCacheHit()
    {
        $executed = 0;
        $executor = function() use (&$executed) {
            $executed++;
            return new ToolResult(['content' => 'file content'], false);
        };
        
        // First call - should execute
        $result1 = $this->cacheManager->getOrExecute('read_file', ['path' => 'test.txt'], $executor);
        $this->assertEquals(1, $executed);
        $this->assertFalse($result1->isError);
        $this->assertEquals('file content', $result1->content['content']);
        
        // Second call - should use cache
        $result2 = $this->cacheManager->getOrExecute('read_file', ['path' => 'test.txt'], $executor);
        $this->assertEquals(1, $executed); // Not executed again
        $this->assertFalse($result2->isError);
        $this->assertEquals('file content', $result2->content['content']);
    }
    
    /**
     * Test cache miss
     */
    public function testCacheMiss()
    {
        $executed = 0;
        $executor = function() use (&$executed) {
            $executed++;
            return new ToolResult(['content' => 'content ' . $executed], false);
        };
        
        // Different parameters should cause cache miss
        $result1 = $this->cacheManager->getOrExecute('read_file', ['path' => 'file1.txt'], $executor);
        $this->assertEquals(1, $executed);
        
        $result2 = $this->cacheManager->getOrExecute('read_file', ['path' => 'file2.txt'], $executor);
        $this->assertEquals(2, $executed);
        
        $this->assertNotEquals($result1->content['content'], $result2->content['content']);
    }
    
    /**
     * Test non-cacheable tool
     */
    public function testNonCacheableTool()
    {
        $executed = 0;
        $executor = function() use (&$executed) {
            $executed++;
            return new ToolResult(['output' => 'command output'], false);
        };
        
        // Non-cacheable tool should always execute
        $this->cacheManager->getOrExecute('bash', ['command' => 'ls'], $executor);
        $this->assertEquals(1, $executed);
        
        $this->cacheManager->getOrExecute('bash', ['command' => 'ls'], $executor);
        $this->assertEquals(2, $executed); // Executed again
    }
    
    /**
     * Test cache key generation
     */
    public function testCacheKeyGeneration()
    {
        $strategy = new CacheKeyStrategy('hash');
        
        // Same params should generate same key
        $key1 = $strategy->generateKey('read_file', ['path' => 'test.txt', 'mode' => 'r']);
        $key2 = $strategy->generateKey('read_file', ['path' => 'test.txt', 'mode' => 'r']);
        $this->assertEquals($key1, $key2);
        
        // Different params should generate different keys
        $key3 = $strategy->generateKey('read_file', ['path' => 'other.txt', 'mode' => 'r']);
        $this->assertNotEquals($key1, $key3);
        
        // Different order should generate same key (normalized)
        $key4 = $strategy->generateKey('read_file', ['mode' => 'r', 'path' => 'test.txt']);
        $this->assertEquals($key1, $key4);
    }
    
    /**
     * Test readable key strategy
     */
    public function testReadableKeyStrategy()
    {
        $strategy = new CacheKeyStrategy('readable');
        
        $key = $strategy->generateKey('read_file', ['path' => 'test.txt', 'mode' => 'r']);
        $this->assertStringContainsString('read_file', $key);
        $this->assertStringContainsString('path=test', $key);
        $this->assertStringContainsString('mode=r', $key);
    }
    
    /**
     * Test cache statistics
     */
    public function testCacheStatistics()
    {
        $stats = new CacheStatistics();
        
        // Record hits and misses
        $stats->recordHit('read_file', 0.5, 0.01);
        $stats->recordHit('read_file', 0.3, 0.01);
        $stats->recordMiss('grep', 'cache_miss');
        $stats->recordMiss('bash', 'not_cacheable');
        
        // Check statistics
        $this->assertEquals(1.0, $stats->getHitRate('read_file')); // 2 hits, 0 misses
        $this->assertEquals(0.0, $stats->getHitRate('grep')); // 0 hits, 1 miss
        $this->assertEquals(0.5, $stats->getHitRate()); // Overall: 2 hits, 2 misses
        
        $report = $stats->toArray();
        $this->assertEquals(2, $report['total_hits']);
        $this->assertEquals(2, $report['total_misses']);
        $this->assertEquals(0.8, $report['saved_time_seconds']);
        $this->assertEquals(0.02, $report['saved_cost_usd']);
    }
    
    /**
     * Test file cache adapter
     */
    public function testFileCacheAdapter()
    {
        $tempDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
        $cache = new FileCacheAdapter($tempDir, 60);
        
        // Test basic operations
        $this->assertFalse($cache->has('key1'));
        
        $cache->set('key1', 'value1', 60);
        $this->assertTrue($cache->has('key1'));
        $this->assertEquals('value1', $cache->get('key1'));
        
        $cache->delete('key1');
        $this->assertFalse($cache->has('key1'));
        
        // Clean up
        $cache->clear();
        rmdir($tempDir);
    }
    
    /**
     * Test cache TTL
     */
    public function testCacheTTL()
    {
        $cache = new MemoryCacheAdapter();
        
        // Set with 1 second TTL
        $cache->set('temp_key', 'temp_value', 1);
        $this->assertTrue($cache->has('temp_key'));
        
        // Wait for expiration
        sleep(2);
        $this->assertFalse($cache->has('temp_key'));
        $this->assertNull($cache->get('temp_key'));
    }
    
    /**
     * Test cache warming
     */
    public function testCacheWarming()
    {
        $warmupData = [
            [
                'tool' => 'read_file',
                'params' => ['path' => 'config.php'],
                'result' => ['content' => 'config content'],
            ],
            [
                'tool' => 'grep',
                'params' => ['pattern' => 'test', 'path' => '.'],
                'result' => ['matches' => ['line1', 'line2']],
            ],
        ];
        
        $this->cacheManager->warmUp($warmupData);
        
        // Check warmed data is available
        $executed = false;
        $result = $this->cacheManager->getOrExecute(
            'read_file',
            ['path' => 'config.php'],
            function() use (&$executed) {
                $executed = true;
                return new ToolResult('should not execute', true);
            }
        );
        
        $this->assertFalse($executed); // Should use warmed cache
        $this->assertFalse($result->isError);
        $this->assertEquals('config content', $result->content['content']);
    }
    
    /**
     * Test cache clearing
     */
    public function testCacheClearing()
    {
        // Add some cached data
        $this->cacheManager->getOrExecute('read_file', ['path' => 'test.txt'], function() {
            return new ToolResult(['content' => 'test'], false);
        });
        
        // Clear cache
        $this->cacheManager->clear();
        
        // Check cache is empty
        $executed = false;
        $this->cacheManager->getOrExecute('read_file', ['path' => 'test.txt'], function() use (&$executed) {
            $executed = true;
            return new ToolResult(['content' => 'new'], false);
        });
        
        $this->assertTrue($executed); // Should execute since cache was cleared
    }
    
    /**
     * Test error result caching
     */
    public function testErrorResultCaching()
    {
        $config = [
            'cacheable_tools' => ['test_tool'],
            'cache_empty_results' => false, // Don't cache empty results
        ];
        
        $cache = new MemoryCacheAdapter();
        $manager = new ToolCacheManager($cache, $config);
        
        $execCount = 0;
        
        // Error results should not be cached by default
        $result1 = $manager->getOrExecute('test_tool', ['param' => 'test'], function() use (&$execCount) {
            $execCount++;
            return new ToolResult('Error occurred', true);
        });
        
        $result2 = $manager->getOrExecute('test_tool', ['param' => 'test'], function() use (&$execCount) {
            $execCount++;
            return new ToolResult('Error occurred', true);
        });
        
        $this->assertEquals(2, $execCount); // Should execute twice (not cached)
    }
    
    /**
     * Test large result handling
     */
    public function testLargeResultHandling()
    {
        $config = [
            'cacheable_tools' => ['test_tool'],
            'max_cache_size' => 100, // Small size limit
        ];
        
        $cache = new MemoryCacheAdapter();
        $manager = new ToolCacheManager($cache, $config);
        
        $largeData = str_repeat('x', 200); // Larger than limit
        $execCount = 0;
        
        // Large results should not be cached
        $result1 = $manager->getOrExecute('test_tool', ['param' => 'test'], function() use (&$execCount, $largeData) {
            $execCount++;
            return new ToolResult(['data' => $largeData], false);
        });
        
        $result2 = $manager->getOrExecute('test_tool', ['param' => 'test'], function() use (&$execCount, $largeData) {
            $execCount++;
            return new ToolResult(['data' => $largeData], false);
        });
        
        $this->assertEquals(2, $execCount); // Should execute twice (not cached due to size)
    }
}