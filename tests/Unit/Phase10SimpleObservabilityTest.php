<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Telemetry\SimpleTracingManager;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class Phase10SimpleObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SimpleTracingManager::clear();
        SimpleMetricsCollector::clear();
        SimpleCostTracker::clear();
        SimpleEventDispatcher::clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SimpleTracingManager::clear();
        SimpleMetricsCollector::clear();
        SimpleCostTracker::clear();
        SimpleEventDispatcher::clear();
    }

    /**
     * Test SimpleTracingManager singleton.
     */
    public function testSimpleTracingManagerSingleton(): void
    {
        $manager1 = SimpleTracingManager::getInstance();
        $manager2 = SimpleTracingManager::getInstance();
        
        $this->assertSame($manager1, $manager2);
    }

    /**
     * Test span creation and tracking.
     */
    public function testSpanCreation(): void
    {
        $tracer = SimpleTracingManager::getInstance();
        $tracer->setEnabled(true);

        // Start a span
        $spanId = $tracer->startSpan('test_operation', 'test');
        $this->assertNotEmpty($spanId);
        $this->assertEquals(1, $tracer->getActiveSpansCount());

        // Add event to span
        $tracer->addEvent($spanId, 'checkpoint', ['step' => 1]);

        // End span
        $tracer->endSpan($spanId, ['result' => 'success']);
        $this->assertEquals(0, $tracer->getActiveSpansCount());

        // Check recorded spans
        $spans = $tracer->getSpans();
        $this->assertCount(1, $spans);
        
        $span = $spans->first();
        $this->assertEquals('test_operation', $span['name']);
        $this->assertEquals('success', $span['status']);
        $this->assertArrayHasKey('duration_ms', $span);
        $this->assertCount(1, $span['events']);
    }

    /**
     * Test SimpleMetricsCollector.
     */
    public function testSimpleMetricsCollector(): void
    {
        $metrics = SimpleMetricsCollector::getInstance();
        
        // Test counter
        $metrics->increment('requests', 1, ['endpoint' => '/api']);
        $metrics->increment('requests', 2, ['endpoint' => '/api']);
        
        $value = $metrics->getCounter('requests', ['endpoint' => '/api']);
        $this->assertEquals(3, $value);

        // Test gauge
        $metrics->setGauge('memory_usage', 1024.5);
        $value = $metrics->getGauge('memory_usage');
        $this->assertEquals(1024.5, $value);

        // Test histogram
        $metrics->recordValue('response_time', 100);
        $metrics->recordValue('response_time', 200);
        $metrics->recordValue('response_time', 150);
        
        $stats = $metrics->getHistogramStats('response_time');
        $this->assertEquals(3, $stats['count']);
        $this->assertEquals(100, $stats['min']);
        $this->assertEquals(200, $stats['max']);
        $this->assertEquals(150, $stats['avg']);
    }

    /**
     * Test SimpleCostTracker.
     */
    public function testSimpleCostTracker(): void
    {
        $tracker = SimpleCostTracker::getInstance();
        
        // Track LLM cost
        $cost = $tracker->trackLLM('claude-3-sonnet', 1000, 2000, 'session1');
        
        // Claude-3-sonnet: $3/1M input, $15/1M output
        $expectedCost = (1000 / 1_000_000 * 3.0) + (2000 / 1_000_000 * 15.0);
        $this->assertEquals(round($expectedCost, 6), $cost);
        
        // Get session total
        $sessionTotal = $tracker->getSessionTotal('session1');
        $this->assertEquals($cost, $sessionTotal);
        
        // Get summary
        $summary = $tracker->getSummary();
        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('by_model', $summary);
        $this->assertArrayHasKey('by_session', $summary);
        $this->assertEquals($cost, $summary['total']);
    }

    /**
     * Test SimpleEventDispatcher.
     */
    public function testSimpleEventDispatcher(): void
    {
        $dispatcher = SimpleEventDispatcher::getInstance();
        
        $received = null;
        
        // Register listener
        $listenerId = $dispatcher->on('test.event', function ($data) use (&$received) {
            $received = $data;
        });
        
        // Dispatch event
        $dispatcher->dispatch('test.event', ['key' => 'value']);
        
        $this->assertEquals(['key' => 'value'], $received);
        
        // Remove listener
        $dispatcher->off($listenerId);
        
        // Test it no longer receives
        $received = null;
        $dispatcher->dispatch('test.event', ['key2' => 'value2']);
        $this->assertNull($received);
    }

    /**
     * Test observability integration.
     */
    public function testObservabilityIntegration(): void
    {
        $tracer = SimpleTracingManager::getInstance();
        $metrics = SimpleMetricsCollector::getInstance();
        $costs = SimpleCostTracker::getInstance();
        $events = SimpleEventDispatcher::getInstance();
        
        // Simulate an LLM request flow
        $spanId = $tracer->startSpan('llm_request', 'llm', ['model' => 'claude-3-sonnet']);
        $events->dispatch('llm.start', ['model' => 'claude-3-sonnet']);
        
        // Track metrics
        $metrics->increment('llm.requests');
        $startTime = microtime(true);
        
        // Simulate processing
        usleep(10000); // 10ms
        
        $duration = (microtime(true) - $startTime) * 1000;
        
        // Track results
        $inputTokens = 100;
        $outputTokens = 200;
        
        $metrics->recordValue('llm.duration', $duration);
        $metrics->increment('llm.tokens.input', $inputTokens);
        $metrics->increment('llm.tokens.output', $outputTokens);
        
        $cost = $costs->trackLLM('claude-3-sonnet', $inputTokens, $outputTokens);
        
        // End span
        $tracer->endSpan($spanId, [
            'tokens' => $inputTokens + $outputTokens,
            'cost' => $cost,
        ]);
        
        $events->dispatch('llm.complete', [
            'model' => 'claude-3-sonnet',
            'duration' => $duration,
            'cost' => $cost,
        ]);
        
        // Verify everything was tracked
        $this->assertEquals(1, $metrics->getCounter('llm.requests'));
        $this->assertGreaterThan(0, $cost);
        $this->assertCount(1, $tracer->getSpans());
    }
}

/**
 * Simplified metrics collector for testing.
 */
class SimpleMetricsCollector
{
    private static ?self $instance = null;
    private array $counters = [];
    private array $gauges = [];
    private array $histograms = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function increment(string $name, float $value = 1, array $labels = []): void
    {
        $key = $this->getKey($name, $labels);
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $value;
    }

    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $key = $this->getKey($name, $labels);
        $this->gauges[$key] = $value;
    }

    public function recordValue(string $name, float $value, array $labels = []): void
    {
        $key = $this->getKey($name, $labels);
        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = [];
        }
        $this->histograms[$key][] = $value;
    }

    public function getCounter(string $name, array $labels = []): float
    {
        return $this->counters[$this->getKey($name, $labels)] ?? 0;
    }

    public function getGauge(string $name, array $labels = []): float
    {
        return $this->gauges[$this->getKey($name, $labels)] ?? 0;
    }

    public function getHistogramStats(string $name, array $labels = []): array
    {
        $values = $this->histograms[$this->getKey($name, $labels)] ?? [];
        if (empty($values)) {
            return ['count' => 0];
        }
        
        return [
            'count' => count($values),
            'min' => min($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
        ];
    }

    private function getKey(string $name, array $labels): string
    {
        if (empty($labels)) {
            return $name;
        }
        ksort($labels);
        return $name . '{' . http_build_query($labels, '', ',') . '}';
    }

    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->counters = [];
            self::$instance->gauges = [];
            self::$instance->histograms = [];
        }
    }
}

/**
 * Simplified cost tracker for testing.
 */
class SimpleCostTracker
{
    private static ?self $instance = null;
    private array $costs = [];
    private array $sessionCosts = [];
    
    // Default pricing per 1M tokens
    private array $pricing = [
        'claude-3-sonnet' => ['input' => 3.0, 'output' => 15.0],
        'gpt-4' => ['input' => 30.0, 'output' => 60.0],
    ];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function trackLLM(string $model, int $inputTokens, int $outputTokens, string $session = null): float
    {
        $pricing = $this->pricing[$model] ?? ['input' => 0, 'output' => 0];
        $cost = ($inputTokens / 1_000_000 * $pricing['input']) + 
                ($outputTokens / 1_000_000 * $pricing['output']);
        $cost = round($cost, 6);
        
        $this->costs[] = [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost' => $cost,
            'session' => $session,
        ];
        
        if ($session) {
            $this->sessionCosts[$session] = ($this->sessionCosts[$session] ?? 0) + $cost;
        }
        
        return $cost;
    }

    public function getSessionTotal(string $session): float
    {
        return $this->sessionCosts[$session] ?? 0;
    }

    public function getSummary(): array
    {
        $total = array_sum(array_column($this->costs, 'cost'));
        
        $byModel = [];
        foreach ($this->costs as $cost) {
            $model = $cost['model'];
            $byModel[$model] = ($byModel[$model] ?? 0) + $cost['cost'];
        }
        
        return [
            'total' => $total,
            'by_model' => $byModel,
            'by_session' => $this->sessionCosts,
        ];
    }

    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->costs = [];
            self::$instance->sessionCosts = [];
        }
    }
}

/**
 * Simplified event dispatcher for testing.
 */
class SimpleEventDispatcher
{
    private static ?self $instance = null;
    private array $listeners = [];
    private int $listenerId = 0;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function on(string $event, callable $callback): int
    {
        $id = ++$this->listenerId;
        $this->listeners[$event][$id] = $callback;
        return $id;
    }

    public function off(int $id): void
    {
        foreach ($this->listeners as $event => &$listeners) {
            unset($listeners[$id]);
        }
    }

    public function dispatch(string $event, array $data = []): void
    {
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $callback) {
                $callback($data);
            }
        }
    }

    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->listeners = [];
            self::$instance->listenerId = 0;
        }
    }
}