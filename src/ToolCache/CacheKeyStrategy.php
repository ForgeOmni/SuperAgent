<?php

namespace SuperAgent\ToolCache;

class CacheKeyStrategy
{
    private string $strategy;
    private string $prefix = 'tool_cache';
    private string $separator = ':';
    
    public function __construct(string $strategy = 'hash')
    {
        $this->strategy = $strategy;
    }
    
    /**
     * Generate cache key for tool call
     */
    public function generateKey(string $toolName, array $params): string
    {
        $baseKey = $this->prefix . $this->separator . $toolName;
        
        return match ($this->strategy) {
            'hash' => $this->hashStrategy($baseKey, $params),
            'readable' => $this->readableStrategy($baseKey, $params),
            'structured' => $this->structuredStrategy($baseKey, $params),
            'custom' => $this->customStrategy($baseKey, $params),
            default => $this->hashStrategy($baseKey, $params),
        };
    }
    
    /**
     * Hash-based strategy (default)
     */
    private function hashStrategy(string $baseKey, array $params): string
    {
        // Normalize params for consistent hashing
        $normalized = $this->normalizeParams($params);
        $hash = md5(serialize($normalized));
        
        return $baseKey . $this->separator . $hash;
    }
    
    /**
     * Human-readable strategy
     */
    private function readableStrategy(string $baseKey, array $params): string
    {
        $parts = [$baseKey];
        
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . '=' . $this->sanitizeValue($value);
            } elseif (is_array($value) && $this->isSimpleArray($value)) {
                $parts[] = $key . '=' . implode(',', array_map([$this, 'sanitizeValue'], $value));
            } else {
                // Fall back to hash for complex values
                $parts[] = $key . '=' . md5(serialize($value));
            }
        }
        
        return implode($this->separator, $parts);
    }
    
    /**
     * Structured strategy for specific tools
     */
    private function structuredStrategy(string $baseKey, array $params): string
    {
        // Special handling for file operations
        if (str_contains($baseKey, 'read_file') && isset($params['path'])) {
            $path = $this->normalizePath($params['path']);
            $version = $params['version'] ?? 'latest';
            return $baseKey . $this->separator . base64_encode($path) . $this->separator . $version;
        }
        
        // Special handling for search operations
        if (str_contains($baseKey, 'grep') || str_contains($baseKey, 'search')) {
            $pattern = $params['pattern'] ?? $params['query'] ?? '';
            $path = $params['path'] ?? '.';
            return $baseKey . $this->separator . md5($pattern) . $this->separator . md5($path);
        }
        
        // Default to hash strategy
        return $this->hashStrategy($baseKey, $params);
    }
    
    /**
     * Custom strategy (can be overridden)
     */
    protected function customStrategy(string $baseKey, array $params): string
    {
        // Override this method for custom key generation
        return $this->hashStrategy($baseKey, $params);
    }
    
    /**
     * Normalize parameters for consistent hashing
     */
    private function normalizeParams(array $params): array
    {
        // Sort by key for consistency
        ksort($params);
        
        // Normalize values
        array_walk_recursive($params, function (&$value) {
            // Normalize paths
            if (is_string($value) && $this->looksLikePath($value)) {
                $value = $this->normalizePath($value);
            }
            
            // Normalize booleans
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            
            // Trim strings
            if (is_string($value)) {
                $value = trim($value);
            }
        });
        
        return $params;
    }
    
    /**
     * Check if string looks like a file path
     */
    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/') || 
               str_contains($value, '\\') ||
               str_starts_with($value, './') ||
               str_starts_with($value, '../');
    }
    
    /**
     * Normalize file path
     */
    private function normalizePath(string $path): string
    {
        // Convert to forward slashes
        $path = str_replace('\\', '/', $path);
        
        // Remove duplicate slashes
        $path = preg_replace('#/+#', '/', $path);
        
        // Remove trailing slash (except for root)
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        
        // Resolve relative paths if possible
        if (function_exists('realpath') && file_exists($path)) {
            $realPath = realpath($path);
            if ($realPath !== false) {
                return $realPath;
            }
        }
        
        return $path;
    }
    
    /**
     * Sanitize value for readable keys
     */
    private function sanitizeValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_null($value)) {
            return 'null';
        }
        
        $value = (string) $value;
        
        // Replace special characters
        $value = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $value);
        
        // Limit length
        if (strlen($value) > 50) {
            $value = substr($value, 0, 47) . '...';
        }
        
        return $value;
    }
    
    /**
     * Check if array is simple (all scalar values)
     */
    private function isSimpleArray(array $array): bool
    {
        foreach ($array as $value) {
            if (!is_scalar($value) && !is_null($value)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get pattern for clearing cache
     */
    public function getPattern(string $toolName): string
    {
        return $this->prefix . $this->separator . $toolName . '*';
    }
    
    /**
     * Extract tool name from cache key
     */
    public function extractToolName(string $key): ?string
    {
        $parts = explode($this->separator, $key);
        if (count($parts) >= 2 && $parts[0] === $this->prefix) {
            return $parts[1];
        }
        return null;
    }
    
    /**
     * Validate cache key
     */
    public function isValidKey(string $key): bool
    {
        return str_starts_with($key, $this->prefix . $this->separator);
    }
}