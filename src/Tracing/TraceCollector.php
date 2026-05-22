<?php

declare(strict_types=1);

namespace SuperAgent\Tracing;

/**
 * Singleton-style facade over a RingBuffer + TraceWriter.
 *
 * Producers (DebateOrchestrator, ErrorRecoveryManager, CostAutopilot,
 * BuiltinAgents, etc.) call emitDuration() / emitInstant() at instrumented
 * points; trigger sites call dump() to serialize.
 *
 * The collector is enabled by default. When disabled, every emit becomes a
 * no-op — wrapping with `if ($collector->isEnabled())` is not necessary
 * unless the caller wants to skip computing args.
 *
 * One TraceCollector instance per process / agent run. Inject via constructor
 * or use static getInstance() for legacy call sites.
 */
final class TraceCollector
{
    private static ?self $instance = null;

    private RingBuffer $ring;
    private bool $enabled;
    private string $sessionId;
    private ?TraceWriter $writer = null;

    public function __construct(
        ?RingBuffer $ring = null,
        bool $enabled = true,
        ?string $sessionId = null,
        ?TraceWriter $writer = null,
    ) {
        $this->ring = $ring ?? new RingBuffer(1024);
        $this->enabled = $enabled;
        $this->sessionId = $sessionId ?? bin2hex(random_bytes(8));
        $this->writer = $writer;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $storagePath = getenv('SUPERAGENT_TRACE_PATH')
                ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superagent-traces');
            $enabled = (getenv('SUPERAGENT_TRACE_ENABLED') ?: 'true') !== 'false';

            self::$instance = new self(
                ring: new RingBuffer((int) (getenv('SUPERAGENT_TRACE_RING_SIZE') ?: 1024)),
                enabled: $enabled,
                sessionId: null,
                writer: new TraceWriter($storagePath, 'superagent'),
            );
        }

        return self::$instance;
    }

    /** Allow tests / hosts to inject a fresh instance. */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function emitDuration(
        string $name,
        string $category,
        string $tid,
        int $startMicros,
        int $durationMicros,
        array $args = [],
        string $pid = 'superagent',
        ?string $color = null,
    ): void {
        if (!$this->enabled) {
            return;
        }
        $this->ring->push(TraceEvent::duration(
            name: $name,
            category: $category,
            pid: $pid,
            tid: $tid,
            startMicros: $startMicros,
            durationMicros: $durationMicros,
            args: $args,
            color: $color,
        ));
    }

    public function emitInstant(
        string $name,
        string $category,
        string $tid,
        array $args = [],
        string $pid = 'superagent',
        ?string $color = null,
    ): void {
        if (!$this->enabled) {
            return;
        }
        $this->ring->push(TraceEvent::instant(
            name: $name,
            category: $category,
            pid: $pid,
            tid: $tid,
            args: $args,
            color: $color,
        ));
    }

    public function emitCounter(
        string $name,
        string $category,
        string $tid,
        array $values,
        string $pid = 'superagent',
    ): void {
        if (!$this->enabled) {
            return;
        }
        $this->ring->push(TraceEvent::counter(
            name: $name,
            category: $category,
            pid: $pid,
            tid: $tid,
            values: $values,
        ));
    }

    /**
     * Convenience for "I started something, give me back a closer".
     *
     * Returns a callable that, when invoked, emits a duration event covering
     * the time between span() and the callback firing.
     *
     * Example:
     *   $end = $collector->span('llm.dispatch', 'llm', 'session:abc');
     *   $result = $llm->call(...);
     *   $end(['model' => $result->model, 'cost_usd' => $result->cost]);
     */
    public function span(
        string $name,
        string $category,
        string $tid,
        array $initialArgs = [],
        string $pid = 'superagent',
    ): callable {
        $start = (int) (microtime(true) * 1_000_000);

        return function (array $extraArgs = []) use ($name, $category, $tid, $start, $initialArgs, $pid): void {
            $now = (int) (microtime(true) * 1_000_000);
            $this->emitDuration(
                name: $name,
                category: $category,
                tid: $tid,
                startMicros: $start,
                durationMicros: $now - $start,
                args: array_merge($initialArgs, $extraArgs),
                pid: $pid,
            );
        };
    }

    /**
     * Serialize ring contents to disk.
     *
     * Returns the path of the written file, or null when no writer is
     * configured or tracing is disabled.
     */
    public function dump(string $trigger, ?string $reason = null, array $extraMetadata = []): ?string
    {
        if (!$this->enabled || $this->writer === null) {
            return null;
        }

        $events = $this->ring->snapshot();
        if (empty($events)) {
            return null;
        }

        return $this->writer->write(
            events: $events,
            sessionOrJobId: $this->sessionId,
            trigger: $trigger,
            triggerReason: $reason,
            extraMetadata: $extraMetadata,
        );
    }

    public function getRing(): RingBuffer
    {
        return $this->ring;
    }

    public function setWriter(TraceWriter $writer): void
    {
        $this->writer = $writer;
    }
}
