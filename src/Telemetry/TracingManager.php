<?php

namespace SuperAgent\Telemetry;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class TracingManager
{
    private static ?self $instance = null;
    private ?TracerProviderInterface $tracerProvider = null;
    private ?TracerInterface $tracer = null;
    private Collection $activeSpans;
    private Collection $spanMetadata;
    private bool $enabled;
    private array $exporters = [];

    public function __construct()
    {
        $this->activeSpans = collect();
        $this->spanMetadata = collect();
        $masterEnabled = config('superagent.telemetry.enabled', false);
        $this->enabled = $masterEnabled && config('superagent.telemetry.tracing.enabled', true);
        
        if ($this->enabled) {
            $this->initialize();
        }
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
     * Initialize OpenTelemetry tracing.
     */
    private function initialize(): void
    {
        $builder = new TracerProviderBuilder();

        // Add configured exporters
        $this->configureExporters($builder);

        // Build tracer provider
        $this->tracerProvider = $builder->build();
        
        // Create tracer
        $this->tracer = $this->tracerProvider->getTracer(
            'superagent',
            '1.0.0',
            'https://github.com/yourusername/superagent',
            Attributes::create([
                'service.name' => 'superagent',
                'service.version' => '1.0.0',
                'deployment.environment' => app()->environment(),
            ])
        );
    }

    /**
     * Configure exporters based on configuration.
     */
    private function configureExporters(TracerProviderBuilder $builder): void
    {
        $exporterConfigs = config('superagent.telemetry.exporters', ['console']);

        foreach ($exporterConfigs as $exporterType => $config) {
            if (is_numeric($exporterType)) {
                $exporterType = $config;
                $config = [];
            }

            $exporter = match ($exporterType) {
                'console' => new ConsoleSpanExporter(),
                'otlp' => $this->createOtlpExporter($config),
                'file' => new FileSpanExporter($config['path'] ?? storage_path('logs/traces.jsonl')),
                'bigquery' => new BigQuerySpanExporter($config),
                'elasticsearch' => new ElasticsearchSpanExporter($config),
                default => null,
            };

            if ($exporter) {
                $this->exporters[] = $exporter;
                $processor = new SimpleSpanProcessor($exporter);
                $builder->addSpanProcessor($processor);
            }
        }
    }

    /**
     * Start an interaction span (root span for a user interaction).
     */
    public function startInteractionSpan(string $name, array $attributes = []): ?SpanInterface
    {
        if (!$this->enabled || !$this->tracer) {
            return null;
        }

        $span = $this->tracer
            ->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes(Attributes::create(array_merge([
                'span.type' => 'interaction',
                'interaction.timestamp' => Carbon::now()->toIso8601String(),
                'interaction.id' => uniqid('interaction_'),
            ], $attributes)))
            ->startSpan();

        $spanId = $span->getContext()->getSpanId();
        $this->activeSpans->put($spanId, $span);
        $this->spanMetadata->put($spanId, [
            'type' => 'interaction',
            'startTime' => microtime(true),
            'attributes' => $attributes,
        ]);

        // Set as current span in context
        Context::storage()->attach($span->storeInContext(Context::getCurrent()));

        return $span;
    }

    /**
     * Start an LLM request span.
     */
    public function startLLMRequestSpan(
        string $model,
        array $messages,
        array $attributes = []
    ): ?SpanInterface {
        if (!$this->enabled || !$this->tracer) {
            return null;
        }

        $span = $this->tracer
            ->spanBuilder("LLM Request: {$model}")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes(Attributes::create(array_merge([
                'span.type' => 'llm_request',
                'llm.model' => $model,
                'llm.message_count' => count($messages),
                'llm.timestamp' => Carbon::now()->toIso8601String(),
            ], $attributes)))
            ->startSpan();

        // Record message details (truncated for performance)
        foreach ($messages as $index => $message) {
            $span->setAttribute("llm.message.{$index}.role", $message['role'] ?? 'unknown');
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $span->setAttribute(
                    "llm.message.{$index}.content_preview",
                    substr($content, 0, 100)
                );
            }
        }

        $spanId = $span->getContext()->getSpanId();
        $this->activeSpans->put($spanId, $span);
        $this->spanMetadata->put($spanId, [
            'type' => 'llm_request',
            'startTime' => microtime(true),
            'model' => $model,
            'messageCount' => count($messages),
        ]);

        return $span;
    }

    /**
     * Start a tool execution span.
     */
    public function startToolSpan(
        string $toolName,
        array $input,
        array $attributes = []
    ): ?SpanInterface {
        if (!$this->enabled || !$this->tracer) {
            return null;
        }

        $span = $this->tracer
            ->spanBuilder("Tool: {$toolName}")
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes(Attributes::create(array_merge([
                'span.type' => 'tool',
                'tool.name' => $toolName,
                'tool.input_size' => strlen(json_encode($input)),
                'tool.timestamp' => Carbon::now()->toIso8601String(),
            ], $attributes)))
            ->startSpan();

        $spanId = $span->getContext()->getSpanId();
        $this->activeSpans->put($spanId, $span);
        $this->spanMetadata->put($spanId, [
            'type' => 'tool',
            'startTime' => microtime(true),
            'toolName' => $toolName,
        ]);

        return $span;
    }

    /**
     * End a span and record its results.
     */
    public function endSpan(SpanInterface $span, array $results = [], ?string $error = null): void
    {
        if (!$span) {
            return;
        }

        $spanId = $span->getContext()->getSpanId();
        $metadata = $this->spanMetadata->get($spanId);

        if ($metadata) {
            $duration = (microtime(true) - $metadata['startTime']) * 1000; // Convert to ms
            $span->setAttribute('duration_ms', $duration);
        }

        // Record results
        if (!empty($results)) {
            foreach ($results as $key => $value) {
                if (is_scalar($value)) {
                    $span->setAttribute("result.{$key}", $value);
                }
            }
        }

        // Record error if present
        if ($error) {
            $span->setStatus(StatusCode::STATUS_ERROR, $error);
            $span->setAttribute('error.message', $error);
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        $span->end();

        // Clean up
        $this->activeSpans->forget($spanId);
        $this->spanMetadata->forget($spanId);
    }

    /**
     * Record an event within the current span.
     */
    public function recordEvent(string $name, array $attributes = []): void
    {
        $currentSpan = $this->getCurrentSpan();
        if ($currentSpan) {
            $currentSpan->addEvent($name, Attributes::create($attributes));
        }
    }

    /**
     * Get the current active span.
     */
    public function getCurrentSpan(): ?SpanInterface
    {
        $context = Context::getCurrent();
        return Span::fromContext($context);
    }

    /**
     * Add attributes to the current span.
     */
    public function addAttributes(array $attributes): void
    {
        $currentSpan = $this->getCurrentSpan();
        if ($currentSpan) {
            foreach ($attributes as $key => $value) {
                if (is_scalar($value)) {
                    $currentSpan->setAttribute($key, $value);
                }
            }
        }
    }

    /**
     * Create OTLP exporter (placeholder - requires gRPC setup).
     */
    private function createOtlpExporter(array $config): ?object
    {
        // This would require OpenTelemetry OTLP exporter package
        // For now, return null
        return null;
    }

    /**
     * Check if tracing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get active spans count.
     */
    public function getActiveSpansCount(): int
    {
        return $this->activeSpans->count();
    }

    /**
     * Get span metadata.
     */
    public function getSpanMetadata(string $spanId): ?array
    {
        return $this->spanMetadata->get($spanId);
    }

    /**
     * Clear all spans (for testing).
     */
    public static function clear(): void
    {
        if (self::$instance) {
            // End all active spans
            foreach (self::$instance->activeSpans as $span) {
                $span->end();
            }
            self::$instance->activeSpans = collect();
            self::$instance->spanMetadata = collect();
        }
    }
}

// Use Span class from OpenTelemetry
use OpenTelemetry\API\Trace\Span;