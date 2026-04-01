<?php

declare(strict_types=1);

namespace SuperAgent\Telemetry;

/**
 * Per-event-type analytics sampling rate control ported from Claude Code.
 *
 * Applies configurable sampling rates to analytics events:
 *  - Each event type can have an independent sample_rate (0.0–1.0)
 *  - Events are sampled probabilistically at log time
 *  - The sample_rate is included in event metadata for downstream correction
 *  - Unconfigured events are logged at 100% (no sampling)
 *
 * Config format: ['event_name' => ['sample_rate' => 0.1], ...]
 */
class EventSampler
{
    /**
     * @var array<string, array{sample_rate: float}> Event-name → sampling config
     */
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Create from a settings array (e.g., loaded from config file or remote).
     */
    public static function fromSettings(array $settings): self
    {
        return new self($settings['event_sampling_config'] ?? []);
    }

    /**
     * Update sampling config at runtime (e.g., from remote config refresh).
     */
    public function updateConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Determine whether an event should be logged, and at what effective rate.
     *
     * Returns:
     *  - null  → log at 100%, no sampling metadata needed
     *  - 0     → drop the event entirely
     *  - float → event was sampled in; attach this rate as metadata
     *
     * @return float|null
     */
    public function shouldSampleEvent(string $eventName): float|null
    {
        if (!isset($this->config[$eventName])) {
            return null; // No config → log everything
        }

        $rate = $this->config[$eventName]['sample_rate'] ?? null;

        if ($rate === null) {
            return null;
        }

        $rate = (float) $rate;

        // Rate >= 1 → log everything (no metadata)
        if ($rate >= 1.0) {
            return null;
        }

        // Rate <= 0 → drop completely
        if ($rate <= 0.0) {
            return 0.0;
        }

        // Probabilistic sampling
        $random = mt_rand() / mt_getrandmax();
        if ($random < $rate) {
            return $rate; // Sampled in — attach rate
        }

        return 0.0; // Sampled out — drop
    }

    /**
     * Enrich event metadata with sample_rate if applicable.
     *
     * @param array $metadata Existing event metadata
     * @return array|null Enriched metadata, or null if event should be dropped
     */
    public function enrichMetadata(string $eventName, array $metadata = []): ?array
    {
        $sampleResult = $this->shouldSampleEvent($eventName);

        // Drop event
        if ($sampleResult === 0.0) {
            return null;
        }

        // Add sample_rate metadata if sampled (not null = has rate)
        if ($sampleResult !== null) {
            $metadata['sample_rate'] = $sampleResult;
        }

        return $metadata;
    }

    /**
     * Log an event through the sampling pipeline.
     *
     * @param string   $eventName Event type name
     * @param array    $metadata  Event metadata
     * @param callable $logger    Actual logging function: fn(string $eventName, array $metadata) => void
     * @return bool Whether the event was logged
     */
    public function logEvent(string $eventName, array $metadata, callable $logger): bool
    {
        $enriched = $this->enrichMetadata($eventName, $metadata);

        if ($enriched === null) {
            return false; // Dropped by sampling
        }

        $logger($eventName, $enriched);
        return true;
    }

    /**
     * Get the configured sample rate for an event.
     *
     * @return float|null Rate (0.0–1.0) or null if unconfigured
     */
    public function getRate(string $eventName): ?float
    {
        if (!isset($this->config[$eventName])) {
            return null;
        }

        return (float) ($this->config[$eventName]['sample_rate'] ?? 1.0);
    }

    /**
     * Get all configured event names.
     */
    public function getConfiguredEvents(): array
    {
        return array_keys($this->config);
    }
}
