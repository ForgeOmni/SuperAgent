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
    private ?EventSampler $eventSampler = null;

    public function __construct()
    {
        $this->spans = collect();
        $this->activeSpans = collect();
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
                error_log('[SuperAgent] Span export failed: ' . $e->getMessage());
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
     * Set the event sampler for per-event-type sampling rate control.
     */
    public function setEventSampler(EventSampler $sampler): void
    {
        $this->eventSampler = $sampler;
    }

    /**
     * Get the event sampler.
     */
    public function getEventSampler(): ?EventSampler
    {
        return $this->eventSampler;
    }

    /**
     * Log a named event through the sampling pipeline.
     *
     * If an EventSampler is configured, events are probabilistically sampled
     * based on per-event-type rates. The sample_rate is attached to metadata
     * for downstream analytics correction.
     *
     * @param string $eventName Event type (e.g., 'tool_execution', 'api_query')
     * @param array  $metadata  Event metadata
     * @return bool Whether the event was logged (false = dropped by sampling)
     */
    public function logEvent(string $eventName, array $metadata = []): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Apply sampling if configured
        if ($this->eventSampler !== null) {
            $enriched = $this->eventSampler->enrichMetadata($eventName, $metadata);
            if ($enriched === null) {
                return false; // Dropped by sampling
            }
            $metadata = $enriched;
        }

        // Create a span-like record for the event
        $event = [
            'type' => 'event',
            'name' => $eventName,
            'timestamp' => microtime(true),
            'metadata' => $metadata,
        ];

        // Export
        foreach ($this->exporters as $exporter) {
            try {
                $exporter($event);
            } catch (\Exception $e) {
                error_log('[SuperAgent] Event export failed: ' . $e->getMessage());
            }
        }

        return true;
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