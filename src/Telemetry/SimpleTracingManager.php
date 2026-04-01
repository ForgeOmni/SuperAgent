<?php

namespace SuperAgent\Telemetry;

use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Simplified tracing manager without OpenTelemetry dependency.
 */
class SimpleTracingManager
{
    private static ?self $instance = null;
    private Collection $spans;
    private Collection $activeSpans;
    private bool $enabled = true;
    private array $exporters = [];

    private function __construct()
    {
        $this->spans = collect();
        $this->activeSpans = collect();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Start a span.
     */
    public function startSpan(string $name, string $type = 'generic', array $attributes = []): string
    {
        if (!$this->enabled) {
            return '';
        }

        $spanId = uniqid('span_');
        
        $span = [
            'id' => $spanId,
            'name' => $name,
            'type' => $type,
            'attributes' => $attributes,
            'start_time' => microtime(true),
            'events' => [],
            'status' => 'running',
        ];

        $this->activeSpans->put($spanId, $span);
        
        return $spanId;
    }

    /**
     * End a span.
     */
    public function endSpan(string $spanId, array $results = [], ?string $error = null): void
    {
        if (!$this->enabled || !$this->activeSpans->has($spanId)) {
            return;
        }

        $span = $this->activeSpans->get($spanId);
        $span['end_time'] = microtime(true);
        $span['duration_ms'] = ($span['end_time'] - $span['start_time']) * 1000;
        $span['results'] = $results;
        $span['status'] = $error ? 'error' : 'success';
        
        if ($error) {
            $span['error'] = $error;
        }

        $this->spans->push($span);
        $this->activeSpans->forget($spanId);

        // Export if needed
        $this->exportSpan($span);
    }

    /**
     * Add an event to a span.
     */
    public function addEvent(string $spanId, string $name, array $attributes = []): void
    {
        if (!$this->enabled || !$this->activeSpans->has($spanId)) {
            return;
        }

        $span = $this->activeSpans->get($spanId);
        $span['events'][] = [
            'name' => $name,
            'timestamp' => microtime(true),
            'attributes' => $attributes,
        ];
        $this->activeSpans->put($spanId, $span);
    }

    /**
     * Export span data.
     */
    private function exportSpan(array $span): void
    {
        foreach ($this->exporters as $exporter) {
            try {
                $exporter($span);
            } catch (\Exception $e) {
                // Log but don't fail
            }
        }
    }

    /**
     * Add an exporter.
     */
    public function addExporter(callable $exporter): void
    {
        $this->exporters[] = $exporter;
    }

    /**
     * Get active spans count.
     */
    public function getActiveSpansCount(): int
    {
        return $this->activeSpans->count();
    }

    /**
     * Get all spans.
     */
    public function getSpans(): Collection
    {
        return $this->spans;
    }

    /**
     * Check if enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set enabled state.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Clear all spans.
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->spans = collect();
            self::$instance->activeSpans = collect();
        }
    }
}