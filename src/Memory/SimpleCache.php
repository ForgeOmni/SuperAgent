<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

/**
 * Simple file-based cache implementation for AutoDreamConsolidator
 * This replaces Laravel's Cache Facade dependency
 */
class SimpleCache
{
    private string $cacheDir;
    
    public function __construct(string $basePath)
    {
        $this->cacheDir = $basePath . '/.cache';
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->getFilePath($key);
        
        if (!file_exists($file)) {
            return $default;
        }
        
        $data = unserialize(file_get_contents($file));
        
        // Check expiration
        if (isset($data['expires']) && $data['expires'] < time()) {
            unlink($file);
            return $default;
        }
        
        return $data['value'];
    }
    
    public function put(string $key, mixed $value, int $seconds): bool
    {
        $file = $this->getFilePath($key);
        $data = [
            'value' => $value,
            'expires' => time() + $seconds,
        ];
        
        return file_put_contents($file, serialize($data)) !== false;
    }
    
    public function add(string $key, mixed $value, int $seconds): bool
    {
        if ($this->has($key)) {
            return false;
        }
        
        return $this->put($key, $value, $seconds);
    }
    
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
    
    public function forget(string $key): bool
    {
        $file = $this->getFilePath($key);
        
        if (file_exists($file)) {
            return unlink($file);
        }
        
        return true;
    }
    
    public function flush(): bool
    {
        $files = glob($this->cacheDir . '/*.cache');
        
        foreach ($files as $file) {
            unlink($file);
        }
        
        return true;
    }
    
    private function getFilePath(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}