<?php

namespace SuperAgent\ToolCache\Adapters;

use Psr\SimpleCache\CacheInterface;

class FileCacheAdapter implements CacheInterface
{
    private string $directory;
    private int $defaultTTL;
    
    public function __construct(string $directory = null, int $defaultTTL = 3600)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . '/superagent_cache';
        $this->defaultTTL = $defaultTTL;
        
        // Create directory if not exists
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }
    
    public function get($key, $default = null): mixed
    {
        $filename = $this->getFilename($key);
        
        if (!file_exists($filename)) {
            return $default;
        }
        
        $content = file_get_contents($filename);
        $data = unserialize($content);
        
        // Check expiration
        if ($data['expires'] !== null && $data['expires'] < time()) {
            unlink($filename);
            return $default;
        }
        
        return $data['value'];
    }
    
    public function set($key, $value, $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTTL;
        $expires = $ttl > 0 ? time() + $ttl : null;
        
        $data = [
            'value' => $value,
            'expires' => $expires,
            'created' => time(),
        ];
        
        $filename = $this->getFilename($key);
        $directory = dirname($filename);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        
        return file_put_contents($filename, serialize($data)) !== false;
    }
    
    public function delete($key): bool
    {
        $filename = $this->getFilename($key);
        
        if (file_exists($filename)) {
            return unlink($filename);
        }
        
        return true;
    }
    
    public function clear(): bool
    {
        $files = glob($this->directory . '/*/*.cache');
        
        foreach ($files as $file) {
            unlink($file);
        }
        
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
            if (!$this->set($key, $value, $ttl)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function has($key): bool
    {
        return $this->get($key, $this) !== $this;
    }
    
    /**
     * Get filename for cache key
     */
    private function getFilename(string $key): string
    {
        $hash = md5($key);
        $directory = substr($hash, 0, 2);
        
        return $this->directory . '/' . $directory . '/' . $hash . '.cache';
    }
    
    /**
     * Clean expired cache files
     */
    public function cleanExpired(): int
    {
        $files = glob($this->directory . '/*/*.cache');
        $deleted = 0;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = unserialize($content);
            
            if ($data['expires'] !== null && $data['expires'] < time()) {
                unlink($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }
}