<?php

return [
    /**
     * Tool Cache Configuration
     */
    
    // Enable tool caching globally
    'enabled' => env('SUPERAGENT_TOOL_CACHE_ENABLED', true),
    
    // Cache driver: memory, file, redis, custom
    'driver' => env('SUPERAGENT_TOOL_CACHE_DRIVER', 'file'),
    
    // File cache settings
    'file_path' => storage_path('superagent/tool_cache'),
    
    // Default TTL in seconds (5 minutes)
    'default_ttl' => env('SUPERAGENT_TOOL_CACHE_TTL', 300),
    
    // Maximum age for stale cache (24 hours)
    'max_stale_age' => 86400,
    
    // Maximum cacheable result size (1MB)
    'max_cache_size' => 1048576,
    
    // Cache empty results
    'cache_empty_results' => false,
    
    // Cache version (increment to invalidate all cache)
    'cache_version' => '1.0',
    
    // Cache key generation strategy: hash, readable, structured
    'key_strategy' => 'hash',
    
    /**
     * Cacheable Tools
     * Tools that are safe to cache (read-only operations)
     */
    'cacheable_tools' => [
        'read_file',
        'file_read',
        'glob',
        'grep',
        'search',
        'list_files',
        'get_file_info',
        'tool_search',
        'ls',
        'cat',
        'find',
        'which',
        'pwd',
        'env',
    ],
    
    /**
     * Non-Cacheable Tools
     * Tools that should never be cached (write operations or dynamic)
     */
    'non_cacheable_tools' => [
        'write_file',
        'edit_file',
        'delete_file',
        'multi_edit',
        'bash',
        'execute',
        'run_command',
        'git',
        'npm',
        'composer',
        'send_message',
        'agent',
    ],
    
    /**
     * Tool-Specific TTL
     * Override TTL for specific tools (in seconds)
     */
    'tool_ttl' => [
        'read_file' => 600,      // 10 minutes - files don't change often
        'grep' => 300,           // 5 minutes - search results
        'glob' => 300,           // 5 minutes - file listings
        'search' => 180,         // 3 minutes - search results may change
        'ls' => 60,              // 1 minute - directory listings
        'cat' => 600,            // 10 minutes - file content
        'get_file_info' => 300,  // 5 minutes - file metadata
    ],
    
    /**
     * Cache Warm-up Data
     * Predefined data to warm up the cache
     */
    'warmup' => [
        // Example:
        // [
        //     'tool' => 'read_file',
        //     'params' => ['path' => '/etc/hosts'],
        //     'result' => ['content' => '...'],
        // ],
    ],
    
    /**
     * Statistics Configuration
     */
    'statistics' => [
        'enabled' => true,
        'track_savings' => true, // Track time and cost savings
        'report_interval' => 3600, // Report every hour
    ],
    
    /**
     * Cache Maintenance
     */
    'maintenance' => [
        'auto_clean' => true,
        'clean_interval' => 3600, // Clean expired entries every hour
        'max_entries' => 10000, // Maximum cache entries
    ],
];