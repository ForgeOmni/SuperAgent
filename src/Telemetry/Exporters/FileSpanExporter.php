<?php

namespace SuperAgent\Telemetry\Exporters;

use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;

class FileSpanExporter implements SpanExporterInterface
{
    private string $filePath;
    private bool $shutdown = false;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        
        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Export spans to file.
     */
    public function export(iterable $spans, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->shutdown) {
            return new CompletedFuture(false);
        }

        try {
            foreach ($spans as $span) {
                $spanData = [
                    'name' => $span->getName(),
                    'trace_id' => $span->getContext()->getTraceId(),
                    'span_id' => $span->getContext()->getSpanId(),
                    'parent_span_id' => $span->getParentContext()->getSpanId(),
                    'start_time' => $span->getStartEpochNanos(),
                    'end_time' => $span->getEndEpochNanos(),
                    'attributes' => $this->extractAttributes($span),
                    'events' => $this->extractEvents($span),
                    'status' => [
                        'code' => $span->getStatus()->getCode(),
                        'description' => $span->getStatus()->getDescription(),
                    ],
                    'kind' => $span->getKind(),
                ];

                $json = json_encode($spanData) . "\n";
                file_put_contents($this->filePath, $json, FILE_APPEND | LOCK_EX);
            }

            return new CompletedFuture(true);
        } catch (\Exception $e) {
            logger()->error("Failed to export spans to file: " . $e->getMessage());
            return new CompletedFuture(false);
        }
    }

    /**
     * Shutdown the exporter.
     */
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        $this->shutdown = true;
        return true;
    }

    /**
     * Force flush any pending exports.
     */
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        // File writes are immediate, nothing to flush
        return true;
    }

    /**
     * Extract attributes from span.
     */
    private function extractAttributes($span): array
    {
        $attributes = [];
        foreach ($span->getAttributes() as $key => $value) {
            $attributes[$key] = $value;
        }
        return $attributes;
    }

    /**
     * Extract events from span.
     */
    private function extractEvents($span): array
    {
        $events = [];
        foreach ($span->getEvents() as $event) {
            $events[] = [
                'name' => $event->getName(),
                'timestamp' => $event->getEpochNanos(),
                'attributes' => $event->getAttributes()->toArray(),
            ];
        }
        return $events;
    }
}