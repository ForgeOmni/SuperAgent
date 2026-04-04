<?php

namespace SuperAgent\ToolCache;

class CacheStatistics
{
    private array $hits = [];
    private array $misses = [];
    private array $missReasons = [];
    private float $totalSavedTime = 0;
    private float $totalSavedCost = 0;
    
    /**
     * Record cache hit
     */
    public function recordHit(string $toolName, float $savedTime = 0, float $savedCost = 0): void
    {
        if (!isset($this->hits[$toolName])) {
            $this->hits[$toolName] = 0;
        }
        $this->hits[$toolName]++;
        
        $this->totalSavedTime += $savedTime;
        $this->totalSavedCost += $savedCost;
    }
    
    /**
     * Record cache miss
     */
    public function recordMiss(string $toolName, string $reason = 'cache_miss'): void
    {
        if (!isset($this->misses[$toolName])) {
            $this->misses[$toolName] = 0;
        }
        $this->misses[$toolName]++;
        
        if (!isset($this->missReasons[$reason])) {
            $this->missReasons[$reason] = 0;
        }
        $this->missReasons[$reason]++;
    }
    
    /**
     * Get hit rate for tool
     */
    public function getHitRate(?string $toolName = null): float
    {
        if ($toolName !== null) {
            $hits = $this->hits[$toolName] ?? 0;
            $misses = $this->misses[$toolName] ?? 0;
        } else {
            $hits = array_sum($this->hits);
            $misses = array_sum($this->misses);
        }
        
        $total = $hits + $misses;
        if ($total === 0) {
            return 0;
        }
        
        return $hits / $total;
    }
    
    /**
     * Get top cached tools
     */
    public function getTopCachedTools(int $limit = 10): array
    {
        arsort($this->hits);
        return array_slice($this->hits, 0, $limit, true);
    }
    
    /**
     * Get miss reasons breakdown
     */
    public function getMissReasons(): array
    {
        return $this->missReasons;
    }
    
    /**
     * Get total statistics
     */
    public function toArray(): array
    {
        $totalHits = array_sum($this->hits);
        $totalMisses = array_sum($this->misses);
        
        return [
            'total_hits' => $totalHits,
            'total_misses' => $totalMisses,
            'hit_rate' => $this->getHitRate(),
            'saved_time_seconds' => round($this->totalSavedTime, 2),
            'saved_cost_usd' => round($this->totalSavedCost, 4),
            'hits_by_tool' => $this->hits,
            'misses_by_tool' => $this->misses,
            'miss_reasons' => $this->missReasons,
            'top_cached' => $this->getTopCachedTools(5),
            'efficiency_score' => $this->calculateEfficiencyScore(),
        ];
    }
    
    /**
     * Calculate efficiency score (0-100)
     */
    private function calculateEfficiencyScore(): float
    {
        $hitRate = $this->getHitRate();
        $timeSavingFactor = min(1, $this->totalSavedTime / 3600); // Cap at 1 hour
        $costSavingFactor = min(1, $this->totalSavedCost / 10); // Cap at $10
        
        // Weighted average
        $score = ($hitRate * 50) + ($timeSavingFactor * 30) + ($costSavingFactor * 20);
        
        return round($score, 2);
    }
    
    /**
     * Reset statistics
     */
    public function reset(): void
    {
        $this->hits = [];
        $this->misses = [];
        $this->missReasons = [];
        $this->totalSavedTime = 0;
        $this->totalSavedCost = 0;
    }
    
    /**
     * Merge statistics from another instance
     */
    public function merge(CacheStatistics $other): void
    {
        // Merge hits
        foreach ($other->hits as $tool => $count) {
            $this->hits[$tool] = ($this->hits[$tool] ?? 0) + $count;
        }
        
        // Merge misses
        foreach ($other->misses as $tool => $count) {
            $this->misses[$tool] = ($this->misses[$tool] ?? 0) + $count;
        }
        
        // Merge miss reasons
        foreach ($other->missReasons as $reason => $count) {
            $this->missReasons[$reason] = ($this->missReasons[$reason] ?? 0) + $count;
        }
        
        // Add totals
        $this->totalSavedTime += $other->totalSavedTime;
        $this->totalSavedCost += $other->totalSavedCost;
    }
}