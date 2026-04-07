<?php

namespace SuperAgent\Telemetry;

use Illuminate\Support\Collection;
use Carbon\Carbon;

class MetricsCollector
{
    private static ?self $instance = null;
    private Collection $metrics;
    private Collection $counters;
    private Collection $gauges;
    private Collection $histograms;
    private bool $enabled;

    public function __construct()
    {
        $this->metrics = collect();
        $this->counters = collect();
        $this->gauges = collect();
        $this->histograms = collect();
        $this->enabled = config('superagent.telemetry.enabled', false)
            && config('superagent.telemetry.metrics.enabled', false);
    }

    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Increment a counter metric.
     */
    public function incrementCounter(string $name, float $value = 1, array $labels = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $key = $this->getMetricKey($name, $labels);
        $current = $this->counters->get($key, 0);
        $this->counters->put($key, $current + $value);

        $this->recordMetric('counter', $name, $current + $value, $labels);
    }

    /**
     * Set a gauge metric.
     */
    public function setGauge(string $name, float $value, array $labels = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $key = $this->getMetricKey($name, $labels);
        $this->gauges->put($key, $value);

        $this->recordMetric('gauge', $name, $value, $labels);
    }

    /**
     * Record a histogram metric.
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $key = $this->getMetricKey($name, $labels);
        
        if (!$this->histograms->has($key)) {
            $this->histograms->put($key, collect());
        }
        
        $this->histograms->get($key)->push($value);

        $this->recordMetric('histogram', $name, $value, $labels);
    }

    /**
     * Record timing metric (convenience method).
     */
    public function recordTiming(string $name, float $duration, array $labels = []): void
    {
        $this->recordHistogram("{$name}_duration_ms", $duration, $labels);
    }

    /**
     * Record an LLM request metrics.
     */
    public function recordLLMRequest(
        string $model,
        int $inputTokens,
        int $outputTokens,
        float $duration,
        bool $success = true
    ): void {
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'model' => $model,
            'success' => $success ? 'true' : 'false',
        ];

        // Token counts
        $this->incrementCounter('llm.input_tokens', $inputTokens, $labels);
        $this->incrementCounter('llm.output_tokens', $outputTokens, $labels);
        $this->incrementCounter('llm.total_tokens', $inputTokens + $outputTokens, $labels);

        // Request count
        $this->incrementCounter('llm.requests', 1, $labels);

        // Duration
        $this->recordHistogram('llm.request_duration_ms', $duration, $labels);

        // Cost estimation (if configured)
        $cost = $this->estimateLLMCost($model, $inputTokens, $outputTokens);
        if ($cost > 0) {
            $this->incrementCounter('llm.estimated_cost_usd', $cost, $labels);
        }
    }

    /**
     * Record tool execution metrics.
     */
    public function recordToolExecution(
        string $toolName,
        float $duration,
        bool $success = true,
        ?string $error = null
    ): void {
        if (!$this->enabled) {
            return;
        }

        $labels = [
            'tool' => $toolName,
            'success' => $success ? 'true' : 'false',
        ];

        if ($error) {
            $labels['error_type'] = $this->classifyError($error);
        }

        // Execution count
        $this->incrementCounter('tool.executions', 1, $labels);

        // Duration
        $this->recordHistogram('tool.execution_duration_ms', $duration, $labels);

        // Error count
        if (!$success) {
            $this->incrementCounter('tool.errors', 1, $labels);
        }
    }

    /**
     * Record session metrics.
     */
    public function recordSessionMetrics(array $metrics): void
    {
        if (!$this->enabled) {
            return;
        }

        foreach ($metrics as $name => $value) {
            if (is_numeric($value)) {
                $this->setGauge("session.{$name}", $value);
            }
        }
    }

    /**
     * Get metric statistics.
     */
    public function getStatistics(string $metricName = null): array
    {
        $stats = [];

        if ($metricName) {
            // Get stats for specific metric
            $values = $this->histograms->get($metricName, collect());
            if ($values->isNotEmpty()) {
                $stats = $this->calculateStatistics($values);
            }
        } else {
            // Get all statistics
            $stats = [
                'counters' => $this->counters->toArray(),
                'gauges' => $this->gauges->toArray(),
                'histograms' => $this->histograms->map(function ($values) {
                    return $this->calculateStatistics($values);
                })->toArray(),
            ];
        }

        return $stats;
    }

    /**
     * Calculate statistics for a collection of values.
     */
    private function calculateStatistics(Collection $values): array
    {
        if ($values->isEmpty()) {
            return [];
        }

        $sorted = $values->sort()->values();
        $count = $sorted->count();

        return [
            'count' => $count,
            'min' => $sorted->first(),
            'max' => $sorted->last(),
            'mean' => $sorted->avg(),
            'median' => $sorted->get((int)($count / 2)),
            'p95' => $sorted->get((int)($count * 0.95)),
            'p99' => $sorted->get((int)($count * 0.99)),
            'sum' => $sorted->sum(),
        ];
    }

    /**
     * Estimate LLM cost based on model and token counts.
     */
    private function estimateLLMCost(string $model, int $inputTokens, int $outputTokens): float
    {
        // Pricing per 1M tokens (example rates)
        $pricing = [
            'claude-3-opus' => ['input' => 15.0, 'output' => 75.0],
            'claude-3-sonnet' => ['input' => 3.0, 'output' => 15.0],
            'claude-3-haiku' => ['input' => 0.25, 'output' => 1.25],
            'gpt-4' => ['input' => 30.0, 'output' => 60.0],
            'gpt-3.5-turbo' => ['input' => 0.5, 'output' => 1.5],
        ];

        $modelPricing = $pricing[$model] ?? ['input' => 0, 'output' => 0];

        $inputCost = ($inputTokens / 1_000_000) * $modelPricing['input'];
        $outputCost = ($outputTokens / 1_000_000) * $modelPricing['output'];

        return $inputCost + $outputCost;
    }

    /**
     * Classify error type for metrics.
     */
    private function classifyError(string $error): string
    {
        if (str_contains($error, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($error, 'rate_limit')) {
            return 'rate_limit';
        }
        if (str_contains($error, 'permission')) {
            return 'permission';
        }
        if (str_contains($error, 'network')) {
            return 'network';
        }
        return 'unknown';
    }

    /**
     * Generate metric key with labels.
     */
    private function getMetricKey(string $name, array $labels): string
    {
        if (empty($labels)) {
            return $name;
        }

        ksort($labels);
        $labelStr = collect($labels)
            ->map(fn($value, $key) => "{$key}={$value}")
            ->join(',');

        return "{$name}{{{$labelStr}}}";
    }

    /**
     * Record a metric to storage.
     */
    private function recordMetric(string $type, string $name, float $value, array $labels): void
    {
        $metric = [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'labels' => $labels,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        $this->metrics->push($metric);

        // Export if buffer is full
        if ($this->metrics->count() >= 100) {
            $this->export();
        }
    }

    /**
     * Export collected metrics.
     */
    public function export(): void
    {
        if ($this->metrics->isEmpty()) {
            return;
        }

        $exporters = config('superagent.telemetry.metrics.exporters', []);

        foreach ($exporters as $exporter) {
            try {
                match ($exporter['type']) {
                    'console' => $this->exportToConsole(),
                    'file' => $this->exportToFile($exporter['path'] ?? storage_path('logs/metrics.jsonl')),
                    'prometheus' => $this->exportToPrometheus($exporter),
                    default => null,
                };
            } catch (\Exception $e) {
                logger()->error("Failed to export metrics: " . $e->getMessage());
            }
        }

        // Clear exported metrics
        $this->metrics = collect();
    }

    /**
     * Export metrics to console.
     */
    private function exportToConsole(): void
    {
        foreach ($this->metrics as $metric) {
            logger()->info('Metric', $metric);
        }
    }

    /**
     * Export metrics to file.
     */
    private function exportToFile(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        foreach ($this->metrics as $metric) {
            file_put_contents($path, json_encode($metric) . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Export to Prometheus (placeholder).
     */
    private function exportToPrometheus(array $config): void
    {
        // Would require Prometheus client library
        // For now, this is a placeholder
    }

    /**
     * Clear all metrics (for testing).
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->metrics = collect();
            self::$instance->counters = collect();
            self::$instance->gauges = collect();
            self::$instance->histograms = collect();
        }
    }

    /**
     * Check if metrics collection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}