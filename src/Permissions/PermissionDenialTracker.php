<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

use SuperAgent\Support\DateTime as Carbon;

class PermissionDenialTracker
{
    private array $denials = [];
    private array $circuitBreakers = [];
    
    private const DEFAULT_THRESHOLD = 5;
    private const DEFAULT_TIME_WINDOW = 60; // seconds
    private const DEFAULT_COOLDOWN = 300; // seconds
    
    public function recordDenial(string $toolName, string $reason): void
    {
        $key = $this->getKey($toolName);
        
        if (!isset($this->denials[$key])) {
            $this->denials[$key] = [];
        }
        
        $this->denials[$key][] = [
            'time' => Carbon::now(),
            'reason' => $reason,
        ];
        
        $this->cleanupOldDenials($key);
        $this->checkCircuitBreaker($key);
    }
    
    public function isCircuitBreakerOpen(string $toolName): bool
    {
        $key = $this->getKey($toolName);
        
        if (!isset($this->circuitBreakers[$key])) {
            return false;
        }
        
        $breaker = $this->circuitBreakers[$key];
        
        if ($breaker['status'] !== 'open') {
            return false;
        }
        
        $cooldownEnd = $breaker['openedAt']->copy()->addSeconds(self::DEFAULT_COOLDOWN);
        
        if (Carbon::now()->greaterThan($cooldownEnd)) {
            $this->circuitBreakers[$key]['status'] = 'half-open';
            return false;
        }
        
        return true;
    }
    
    public function getRecentDenialCount(string $toolName): int
    {
        $key = $this->getKey($toolName);
        
        if (!isset($this->denials[$key])) {
            return 0;
        }
        
        $this->cleanupOldDenials($key);
        
        return count($this->denials[$key]);
    }
    
    public function getDenialStatistics(): array
    {
        $stats = [];
        
        foreach ($this->denials as $key => $denialList) {
            $this->cleanupOldDenials($key);
            
            if (count($denialList) > 0) {
                $stats[$key] = [
                    'count' => count($denialList),
                    'reasons' => array_count_values(array_column($denialList, 'reason')),
                    'circuit_breaker' => $this->circuitBreakers[$key]['status'] ?? 'closed',
                ];
            }
        }
        
        return $stats;
    }
    
    public function resetCircuitBreaker(string $toolName): void
    {
        $key = $this->getKey($toolName);
        
        if (isset($this->circuitBreakers[$key])) {
            $this->circuitBreakers[$key]['status'] = 'closed';
            $this->circuitBreakers[$key]['failureCount'] = 0;
        }
        
        if (isset($this->denials[$key])) {
            $this->denials[$key] = [];
        }
    }
    
    public function resetAllCircuitBreakers(): void
    {
        $this->circuitBreakers = [];
        $this->denials = [];
    }
    
    private function getKey(string $toolName): string
    {
        return $toolName === '*' ? 'global' : $toolName;
    }
    
    private function cleanupOldDenials(string $key): void
    {
        if (!isset($this->denials[$key])) {
            return;
        }
        
        $cutoff = Carbon::now()->subSeconds(self::DEFAULT_TIME_WINDOW);
        
        $this->denials[$key] = array_filter(
            $this->denials[$key],
            fn($denial) => $denial['time']->greaterThan($cutoff),
        );
    }
    
    private function checkCircuitBreaker(string $key): void
    {
        if (!isset($this->circuitBreakers[$key])) {
            $this->circuitBreakers[$key] = [
                'status' => 'closed',
                'failureCount' => 0,
                'openedAt' => null,
            ];
        }
        
        $breaker = &$this->circuitBreakers[$key];
        $denialCount = count($this->denials[$key] ?? []);
        
        if ($breaker['status'] === 'closed' || $breaker['status'] === 'half-open') {
            if ($denialCount >= self::DEFAULT_THRESHOLD) {
                $breaker['status'] = 'open';
                $breaker['openedAt'] = Carbon::now();
                $breaker['failureCount'] = $denialCount;
            }
        }
    }
    
    public function getCircuitBreakerStatus(string $toolName): array
    {
        $key = $this->getKey($toolName);
        
        if (!isset($this->circuitBreakers[$key])) {
            return [
                'status' => 'closed',
                'failure_count' => 0,
                'denial_count' => 0,
                'cooldown_remaining' => 0,
            ];
        }
        
        $breaker = $this->circuitBreakers[$key];
        $cooldownRemaining = 0;
        
        if ($breaker['status'] === 'open' && $breaker['openedAt'] !== null) {
            $elapsed = Carbon::now()->diffInSeconds($breaker['openedAt']);
            $cooldownRemaining = max(0, self::DEFAULT_COOLDOWN - $elapsed);
        }
        
        return [
            'status' => $breaker['status'],
            'failure_count' => $breaker['failureCount'],
            'denial_count' => $this->getRecentDenialCount($toolName),
            'cooldown_remaining' => $cooldownRemaining,
        ];
    }
}