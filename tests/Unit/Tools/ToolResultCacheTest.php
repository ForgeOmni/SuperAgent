<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use SuperAgent\Tools\ToolResult;
use SuperAgent\Tools\ToolResultCache;

class ToolResultCacheTest extends TestCase
{
    private function makeResult(string $content = 'result'): ToolResult
    {
        return new ToolResult($content, false);
    }

    private function makeErrorResult(): ToolResult
    {
        return new ToolResult('error occurred', true);
    }

    public function test_cache_miss_returns_null(): void
    {
        $cache = new ToolResultCache();
        $this->assertNull($cache->get('read_file', ['path' => '/foo']));
    }

    public function test_cache_hit_returns_result(): void
    {
        $cache = new ToolResultCache();
        $result = $this->makeResult('file contents');

        $cache->set('read_file', ['path' => '/foo'], $result);
        $cached = $cache->get('read_file', ['path' => '/foo']);

        $this->assertNotNull($cached);
        $this->assertSame('file contents', $cached->content);
    }

    public function test_different_inputs_different_cache_keys(): void
    {
        $cache = new ToolResultCache();

        $cache->set('read_file', ['path' => '/a'], $this->makeResult('a'));
        $cache->set('read_file', ['path' => '/b'], $this->makeResult('b'));

        $this->assertSame('a', $cache->get('read_file', ['path' => '/a'])->content);
        $this->assertSame('b', $cache->get('read_file', ['path' => '/b'])->content);
    }

    public function test_ttl_expiration(): void
    {
        $cache = new ToolResultCache(defaultTtlSeconds: 0); // expires immediately

        $cache->set('read_file', ['path' => '/foo'], $this->makeResult());

        // Sleep a tiny bit to ensure expiration
        usleep(1000);

        $this->assertNull($cache->get('read_file', ['path' => '/foo']));
    }

    public function test_errors_not_cached(): void
    {
        $cache = new ToolResultCache();
        $cache->set('bash', ['command' => 'ls'], $this->makeErrorResult());

        $this->assertNull($cache->get('bash', ['command' => 'ls']));
    }

    public function test_invalidate_by_tool(): void
    {
        $cache = new ToolResultCache();
        $cache->set('read_file', ['path' => '/a'], $this->makeResult('a'));
        $cache->set('read_file', ['path' => '/b'], $this->makeResult('b'));
        $cache->set('grep', ['pattern' => 'x'], $this->makeResult('x'));

        $cache->invalidate('read_file');

        $this->assertNull($cache->get('read_file', ['path' => '/a']));
        $this->assertNull($cache->get('read_file', ['path' => '/b']));
        $this->assertNotNull($cache->get('grep', ['pattern' => 'x']));
    }

    public function test_clear(): void
    {
        $cache = new ToolResultCache();
        $cache->set('a', ['x' => 1], $this->makeResult());
        $cache->set('b', ['x' => 2], $this->makeResult());

        $cache->clear();

        $this->assertNull($cache->get('a', ['x' => 1]));
        $this->assertNull($cache->get('b', ['x' => 2]));
    }

    public function test_stats(): void
    {
        $cache = new ToolResultCache();
        $cache->set('a', ['x' => 1], $this->makeResult());

        $cache->get('a', ['x' => 1]); // hit
        $cache->get('a', ['x' => 2]); // miss
        $cache->get('b', ['x' => 1]); // miss

        $stats = $cache->getStats();
        $this->assertSame(1, $stats['entries']);
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(2, $stats['misses']);
        $this->assertEqualsWithDelta(0.333, $stats['hit_rate'], 0.01);
    }

    public function test_max_entries_eviction(): void
    {
        $cache = new ToolResultCache(maxEntries: 2);

        $cache->set('a', ['x' => 1], $this->makeResult('first'));
        usleep(1000); // small delay to differentiate expiry times
        $cache->set('b', ['x' => 2], $this->makeResult('second'));
        $cache->set('c', ['x' => 3], $this->makeResult('third')); // should evict oldest

        $stats = $cache->getStats();
        $this->assertSame(2, $stats['entries']);
    }

    public function test_input_order_independent(): void
    {
        $cache = new ToolResultCache();
        $cache->set('tool', ['b' => 2, 'a' => 1], $this->makeResult('val'));

        // Same input in different order should hit
        $cached = $cache->get('tool', ['a' => 1, 'b' => 2]);
        $this->assertNotNull($cached);
        $this->assertSame('val', $cached->content);
    }
}
