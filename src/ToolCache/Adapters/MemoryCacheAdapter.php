<?php

namespace SuperAgent\ToolCache\Adapters;

use Psr\SimpleCache\CacheInterface;

class MemoryCacheAdapter implements CacheInterface
{
    private array $cache = [];
    private array $expires = [];
    
    public function get($key, $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }
        
        // Check expiration
        if (isset($this->expires[$key]) && $this->expires[$key] < time()) {
            $this->delete($key);
            return $default;
        }
        
        return $this->cache[$key];
    }
    
    public function set($key, $value, $ttl = null): bool
    {
        $this->cache[$key] = $value;
        
        if ($ttl !== null && $ttl > 0) {
            $this->expires[$key] = time() + $ttl;
        } else {
            unset($this->expires[$key]);
        }
        
        return true;
    }
    
    public function delete($key): bool
    {
        unset($this->cache[$key]);
        unset($this->expires[$key]);
        return true;
    }
    
    public function clear(): bool
    {
        $this->cache = [];
        $this->expires = [];
        return true;
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
            $this->set($key, $value, $ttl);
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
        if (!isset($this->cache[$key])) {
            return false;
        }
        
        // Check expiration
        if (isset($this->expires[$key]) && $this->expires[$key] < time()) {
            $this->delete($key);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get cache size
     */
    public function getSize(): int
    {
        return count($this->cache);
    }
    
    /**
     * Get memory usage
     */
    public function getMemoryUsage(): int
    {
        return strlen(serialize($this->cache));
    }
}