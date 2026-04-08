<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Performance;

use SuperAgent\Swarm\ParallelAgentCoordinator;
use SuperAgent\Swarm\AgentProgressTracker;

/**
 * Performance metrics and profiling for agent execution.
 * Tracks CPU usage, memory, execution time, and throughput.
 */
class AgentPerformanceProfiler
{
    private ParallelAgentCoordinator $coordinator;
    private array $metrics = [];
    private array $profiles = [];
    private float $startTime;
    
    public function __construct(?ParallelAgentCoordinator $coordinator = null)
    {
        $this->coordinator = $coordinator ?? ParallelAgentCoordinator::getInstance();
        $this->startTime = microtime(true);
    }
    
    /**
     * Start profiling an agent.
     */
    public function startProfiling(string $agentId): void
    {
        $this->profiles[$agentId] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'start_peak_memory' => memory_get_peak_usage(true),
            'cpu_start' => $this->getCpuUsage(),
            'tool_timings' => [],
            'token_rates' => [],
            'checkpoints' => [],
        ];
    }
    
    /**
     * Stop profiling an agent.
     */
    public function stopProfiling(string $agentId): array
    {
        if (!isset($this->profiles[$agentId])) {
            return [];
        }
        
        $profile = $this->profiles[$agentId];
        $endTime = microtime(true);
        
        $metrics = [
            'agent_id' => $agentId,
            'duration_seconds' => $endTime - $profile['start_time'],
            'memory_used_mb' => (memory_get_usage(true) - $profile['start_memory']) / 1048576,
            'peak_memory_mb' => memory_get_peak_usage(true) / 1048576,
            'cpu_usage' => $this->getCpuUsage() - $profile['cpu_start'],
            'tool_timings' => $profile['tool_timings'],
            'token_rates' => $this->calculateTokenRates($agentId, $profile),
            'checkpoints' => $profile['checkpoints'],
        ];
        
        $this->metrics[$agentId] = $metrics;
        unset($this->profiles[$agentId]);
        
        return $metrics;
    }
    
    /**
     * Record a tool execution timing.
     */
    public function recordToolTiming(
        string $agentId,
        string $toolName,
        float $duration,
        array $metadata = []
    ): void {
        if (!isset($this->profiles[$agentId])) {
            return;
        }
        
        $this->profiles[$agentId]['tool_timings'][] = [
            'tool' => $toolName,
            'duration_ms' => $duration * 1000,
            'timestamp' => microtime(true),
            'metadata' => $metadata,
        ];
    }
    
    /**
     * Add a profiling checkpoint.
     */
    public function addCheckpoint(string $agentId, string $name, array $data = []): void
    {
        if (!isset($this->profiles[$agentId])) {
            return;
        }
        
        $this->profiles[$agentId]['checkpoints'][] = [
            'name' => $name,
            'timestamp' => microtime(true),
            'memory_mb' => memory_get_usage(true) / 1048576,
            'data' => $data,
        ];
    }
    
    /**
     * Get performance metrics for all agents.
     */
    public function getAllMetrics(): array
    {
        $activeTrackers = $this->coordinator->getActiveTrackers();
        $allMetrics = [];
        
        foreach ($activeTrackers as $agentId => $tracker) {
            $progress = $tracker->getProgress();
            
            $metrics = $this->metrics[$agentId] ?? [];
            $metrics['current'] = [
                'tokens' => $progress['tokenCount'],
                'tool_uses' => $progress['toolUseCount'],
                'duration_ms' => $progress['durationMs'],
            ];
            
            if (isset($this->profiles[$agentId])) {
                $metrics['profiling'] = true;
                $metrics['running_time'] = microtime(true) - $this->profiles[$agentId]['start_time'];
            }
            
            $allMetrics[$agentId] = $metrics;
        }
        
        return $allMetrics;
    }
    
    /**
     * Get aggregated performance statistics.
     */
    public function getAggregatedStats(): array
    {
        $metrics = $this->getAllMetrics();
        
        if (empty($metrics)) {
            return [
                'total_agents' => 0,
                'avg_duration_seconds' => 0,
                'avg_memory_mb' => 0,
                'avg_tokens_per_second' => 0,
                'total_tool_calls' => 0,
            ];
        }
        
        $totalDuration = 0;
        $totalMemory = 0;
        $totalTokens = 0;
        $totalTools = 0;
        $count = 0;
        
        foreach ($metrics as $agentMetrics) {
            if (isset($agentMetrics['duration_seconds'])) {
                $totalDuration += $agentMetrics['duration_seconds'];
                $count++;
            }
            
            if (isset($agentMetrics['memory_used_mb'])) {
                $totalMemory += $agentMetrics['memory_used_mb'];
            }
            
            if (isset($agentMetrics['current'])) {
                $totalTokens += $agentMetrics['current']['tokens'];
                $totalTools += $agentMetrics['current']['tool_uses'];
            }
        }
        
        $avgDuration = $count > 0 ? $totalDuration / $count : 0;
        
        return [
            'total_agents' => count($metrics),
            'avg_duration_seconds' => $avgDuration,
            'avg_memory_mb' => $count > 0 ? $totalMemory / $count : 0,
            'avg_tokens_per_second' => $avgDuration > 0 ? $totalTokens / $totalDuration : 0,
            'total_tool_calls' => $totalTools,
            'total_tokens' => $totalTokens,
            'uptime_seconds' => microtime(true) - $this->startTime,
        ];
    }
    
    /**
     * Get bottleneck analysis.
     */
    public function getBottleneckAnalysis(): array
    {
        $bottlenecks = [];
        $metrics = $this->getAllMetrics();
        
        foreach ($metrics as $agentId => $agentMetrics) {
            if (!isset($agentMetrics['tool_timings'])) {
                continue;
            }
            
            // Find slowest tools
            $slowTools = [];
            foreach ($agentMetrics['tool_timings'] as $timing) {
                if ($timing['duration_ms'] > 1000) { // Tools taking >1 second
                    $slowTools[] = [
                        'tool' => $timing['tool'],
                        'duration_ms' => $timing['duration_ms'],
                    ];
                }
            }
            
            if (!empty($slowTools)) {
                $bottlenecks[$agentId] = [
                    'slow_tools' => $slowTools,
                    'memory_usage_mb' => $agentMetrics['peak_memory_mb'] ?? 0,
                ];
            }
        }
        
        return $bottlenecks;
    }
    
    /**
     * Generate performance report.
     */
    public function generateReport(): array
    {
        return [
            'summary' => $this->getAggregatedStats(),
            'agents' => $this->getAllMetrics(),
            'bottlenecks' => $this->getBottleneckAnalysis(),
            'recommendations' => $this->getRecommendations(),
            'timestamp' => microtime(true),
        ];
    }
    
    /**
     * Get performance recommendations.
     */
    private function getRecommendations(): array
    {
        $recommendations = [];
        $stats = $this->getAggregatedStats();
        
        // Token throughput recommendation
        if ($stats['avg_tokens_per_second'] < 100) {
            $recommendations[] = [
                'type' => 'performance',
                'message' => 'Low token throughput detected. Consider using faster models or optimizing prompts.',
                'severity' => 'warning',
            ];
        }
        
        // Memory usage recommendation
        if ($stats['avg_memory_mb'] > 256) {
            $recommendations[] = [
                'type' => 'memory',
                'message' => 'High memory usage detected. Consider implementing memory limits or cleanup.',
                'severity' => 'warning',
            ];
        }
        
        // Tool usage recommendation
        $bottlenecks = $this->getBottleneckAnalysis();
        if (!empty($bottlenecks)) {
            $recommendations[] = [
                'type' => 'bottleneck',
                'message' => 'Slow tool executions detected. Review tool implementations for optimization.',
                'severity' => 'info',
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Calculate token processing rates.
     */
    private function calculateTokenRates(string $agentId, array $profile): array
    {
        $tracker = $this->coordinator->getTracker($agentId);
        if (!$tracker) {
            return [];
        }
        
        $progress = $tracker->getProgress();
        $duration = microtime(true) - $profile['start_time'];
        
        if ($duration <= 0) {
            return [];
        }
        
        return [
            'tokens_per_second' => $progress['tokenCount'] / $duration,
            'input_tokens_per_second' => $progress['inputTokens'] / $duration,
            'output_tokens_per_second' => $progress['outputTokens'] / $duration,
            'tools_per_minute' => ($progress['toolUseCount'] / $duration) * 60,
        ];
    }
    
    /**
     * Get CPU usage (simplified).
     */
    private function getCpuUsage(): float
    {
        // sys_getloadavg() is not available on Windows
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg()[0] ?? 0.0;
        }
        return 0.0;
    }
    
    /**
     * Export metrics to various formats.
     */
    public function export(string $format = 'json'): string
    {
        $report = $this->generateReport();
        
        return match($format) {
            'json' => json_encode($report, JSON_PRETTY_PRINT),
            'csv' => $this->exportToCsv($report),
            'prometheus' => $this->exportToPrometheus($report),
            default => throw new \InvalidArgumentException("Unsupported format: $format"),
        };
    }
    
    /**
     * Export to CSV format.
     */
    private function exportToCsv(array $report): string
    {
        $csv = "agent_id,duration_seconds,memory_mb,tokens,tools,tokens_per_second\n";
        
        foreach ($report['agents'] as $agentId => $metrics) {
            $csv .= sprintf(
                "%s,%.2f,%.2f,%d,%d,%.2f\n",
                $agentId,
                $metrics['duration_seconds'] ?? 0,
                $metrics['memory_used_mb'] ?? 0,
                $metrics['current']['tokens'] ?? 0,
                $metrics['current']['tool_uses'] ?? 0,
                $metrics['token_rates']['tokens_per_second'] ?? 0
            );
        }
        
        return $csv;
    }
    
    /**
     * Export to Prometheus format.
     */
    private function exportToPrometheus(array $report): string
    {
        $metrics = [];
        
        // Summary metrics
        $summary = $report['summary'];
        $metrics[] = "# HELP agent_total Total number of agents";
        $metrics[] = "# TYPE agent_total gauge";
        $metrics[] = "agent_total {$summary['total_agents']}";
        
        $metrics[] = "# HELP agent_tokens_total Total tokens processed";
        $metrics[] = "# TYPE agent_tokens_total counter";
        $metrics[] = "agent_tokens_total {$summary['total_tokens']}";
        
        $metrics[] = "# HELP agent_uptime_seconds System uptime in seconds";
        $metrics[] = "# TYPE agent_uptime_seconds gauge";
        $metrics[] = "agent_uptime_seconds {$summary['uptime_seconds']}";
        
        return implode("\n", $metrics);
    }
}