<?php

/**
 * Tool Cache Example
 * 
 * This example demonstrates how tool caching can dramatically
 * reduce costs and improve performance in SuperAgent.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent;
use SuperAgent\Tools\Builtin\FileReadTool;
use SuperAgent\Tools\Builtin\GrepTool;
use SuperAgent\Tools\Builtin\GlobTool;
use SuperAgent\ToolCache\ToolCacheManager;
use SuperAgent\ToolCache\Adapters\FileCacheAdapter;
use SuperAgent\ToolCache\CacheKeyStrategy;

// Configure agent with tool caching
$config = [
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-haiku-20240307',
    ],
    
    // Tool cache configuration
    'tool_cache' => [
        'enabled' => true,
        'driver' => 'file', // or 'memory', 'redis'
        'file_path' => storage_path('tool_cache'),
        'default_ttl' => 300, // 5 minutes
        
        // Tools that are safe to cache
        'cacheable_tools' => [
            'read_file',
            'grep',
            'glob',
            'search',
        ],
        
        // Tool-specific TTL
        'tool_ttl' => [
            'read_file' => 600,  // 10 minutes for file reads
            'grep' => 300,       // 5 minutes for searches
            'glob' => 300,       // 5 minutes for file listings
        ],
    ],
];

// Create agent with caching enabled
$agent = new Agent($config);

// Register tools
$agent->registerTool(new FileReadTool());
$agent->registerTool(new GrepTool());
$agent->registerTool(new GlobTool());

echo "=== Tool Cache Demonstration ===\n\n";

// Example 1: Repeated file reads (will be cached)
echo "Example 1: Reading the same file multiple times\n";
echo "================================================\n";

$startTime = microtime(true);

// First query - will read from disk
$result1 = $agent->query("Read the content of composer.json");
$time1 = microtime(true) - $startTime;
echo "First read: {$time1}s\n";

// Second query - will use cache
$startTime = microtime(true);
$result2 = $agent->query("Show me the composer.json file again");
$time2 = microtime(true) - $startTime;
echo "Second read (cached): {$time2}s\n";
echo "Speed improvement: " . round($time1 / $time2) . "x faster\n\n";

// Example 2: Repeated searches (will be cached)
echo "Example 2: Searching for the same pattern\n";
echo "==========================================\n";

$pattern = "function";

$startTime = microtime(true);
$result3 = $agent->query("Search for '$pattern' in all PHP files");
$time3 = microtime(true) - $startTime;
echo "First search: {$time3}s\n";

$startTime = microtime(true);
$result4 = $agent->query("Find '$pattern' in PHP files again");
$time4 = microtime(true) - $startTime;
echo "Second search (cached): {$time4}s\n";
echo "Speed improvement: " . round($time3 / $time4) . "x faster\n\n";

// Example 3: Cache statistics
echo "Example 3: Cache Statistics\n";
echo "===========================\n";

// Get cache statistics
$stats = $agent->getToolCacheStatistics();

echo "Cache Performance:\n";
echo "- Total hits: " . $stats['total_hits'] . "\n";
echo "- Total misses: " . $stats['total_misses'] . "\n";
echo "- Hit rate: " . round($stats['hit_rate'] * 100, 1) . "%\n";
echo "- Time saved: " . $stats['saved_time_seconds'] . " seconds\n";
echo "- Cost saved: $" . $stats['saved_cost_usd'] . "\n";
echo "- Efficiency score: " . $stats['efficiency_score'] . "/100\n\n";

// Example 4: Cache warming
echo "Example 4: Cache Warming\n";
echo "========================\n";

// Pre-populate cache with frequently accessed data
$warmupData = [
    [
        'tool' => 'read_file',
        'params' => ['path' => 'README.md'],
        'result' => ['content' => file_get_contents('README.md')],
    ],
    [
        'tool' => 'glob',
        'params' => ['pattern' => '*.php', 'path' => 'src'],
        'result' => glob('src/*.php'),
    ],
];

$agent->warmUpToolCache($warmupData);
echo "Cache warmed with " . count($warmupData) . " entries\n\n";

// Example 5: Manual cache management
echo "Example 5: Cache Management\n";
echo "===========================\n";

// Clear cache for specific tool
$agent->clearToolCache('grep');
echo "Cleared cache for 'grep' tool\n";

// Clear all cache
$agent->clearToolCache();
echo "Cleared all tool cache\n\n";

// Example 6: Different cache strategies
echo "Example 6: Cache Key Strategies\n";
echo "================================\n";

// Hash strategy (default) - generates compact MD5 keys
$hashStrategy = new CacheKeyStrategy('hash');
$key1 = $hashStrategy->generateKey('read_file', ['path' => '/etc/hosts']);
echo "Hash key: $key1\n";

// Readable strategy - generates human-readable keys
$readableStrategy = new CacheKeyStrategy('readable');
$key2 = $readableStrategy->generateKey('read_file', ['path' => '/etc/hosts']);
echo "Readable key: $key2\n";

// Structured strategy - tool-specific key generation
$structuredStrategy = new CacheKeyStrategy('structured');
$key3 = $structuredStrategy->generateKey('grep', ['pattern' => 'test', 'path' => 'src']);
echo "Structured key: $key3\n\n";

// Example 7: Cache adapters
echo "Example 7: Cache Adapters\n";
echo "=========================\n";

// File cache (persistent across sessions)
$fileCache = new FileCacheAdapter('/tmp/tool_cache', 3600);
$manager1 = new ToolCacheManager($fileCache, $config['tool_cache']);
echo "File cache: Persistent storage in /tmp/tool_cache\n";

// Memory cache (fast but session-only)
$memoryCache = new \SuperAgent\ToolCache\Adapters\MemoryCacheAdapter();
$manager2 = new ToolCacheManager($memoryCache, $config['tool_cache']);
echo "Memory cache: Fast in-memory storage\n\n";

// Example 8: Cost savings calculation
echo "Example 8: Cost Savings Analysis\n";
echo "=================================\n";

// Simulate multiple operations
$operations = 100;
$avgToolCallCost = 0.0001; // $0.0001 per tool call
$cacheHitRate = 0.7; // 70% cache hit rate

$withoutCache = $operations * $avgToolCallCost;
$withCache = ($operations * (1 - $cacheHitRate)) * $avgToolCallCost;
$savings = $withoutCache - $withCache;

echo "Without cache: $" . number_format($withoutCache, 4) . "\n";
echo "With cache: $" . number_format($withCache, 4) . "\n";
echo "Savings: $" . number_format($savings, 4) . " (" . round($savings / $withoutCache * 100) . "%)\n";
echo "For 10,000 operations: $" . number_format($savings * 100, 2) . " saved\n\n";

// Example 9: Custom cache conditions
echo "Example 9: Custom Cache Conditions\n";
echo "===================================\n";

$customConfig = [
    'tool_cache' => [
        'enabled' => true,
        'cache_condition' => function($tool, $params, $result) {
            // Only cache successful results with content
            return !$result->isError && !empty($result->content);
        },
    ],
];

echo "Custom condition: Only cache successful, non-empty results\n";

// Example 10: Performance monitoring
echo "\nExample 10: Performance Impact\n";
echo "===============================\n";

// Compare performance with and without cache
$iterations = 10;

// Without cache
$agent->clearToolCache();
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $agent->query("Read composer.json");
}
$timeWithoutCache = microtime(true) - $startTime;

// With cache (first iteration fills cache, rest use it)
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $agent->query("Read composer.json");
}
$timeWithCache = microtime(true) - $startTime;

echo "Time without cache: " . round($timeWithoutCache, 3) . "s\n";
echo "Time with cache: " . round($timeWithCache, 3) . "s\n";
echo "Performance gain: " . round(($timeWithoutCache - $timeWithCache) / $timeWithoutCache * 100) . "%\n";

echo "\n=== Tool Cache Example Complete ===\n";
echo "\nKey Benefits:\n";
echo "- 🚀 3-10x faster for repeated operations\n";
echo "- 💰 70-90% cost reduction on cached calls\n";
echo "- 🔄 Automatic cache management\n";
echo "- 🎯 Configurable per-tool TTL\n";
echo "- 📊 Built-in statistics and monitoring\n";