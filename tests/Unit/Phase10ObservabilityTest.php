<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Telemetry\TracingManager;
use SuperAgent\Telemetry\MetricsCollector;
use SuperAgent\Telemetry\StructuredLogger;
use SuperAgent\Telemetry\CostTracker;
use SuperAgent\Telemetry\EventDispatcher;
use SuperAgent\Telemetry\Exporters\FileSpanExporter;
use Carbon\Carbon;

class Phase10ObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear singleton instances
        TracingManager::clear();
        MetricsCollector::clear();
        CostTracker::clear();
        EventDispatcher::clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clear singleton instances
        TracingManager::clear();
        MetricsCollector::clear();
        CostTracker::clear();
        EventDispatcher::clear();
    }

    /**
     * Test TracingManager singleton.
     */
    public function testTracingManagerSingleton(): void
    {
        $manager1 = TracingManager::getInstance();
        $manager2 = TracingManager::getInstance();
        
        $this->assertSame($manager1, $manager2, 'TracingManager should implement singleton pattern');
    }

    /**
     * Test span creation and tracking.
     */
    public function testSpanCreation(): void
    {
        $tracer = TracingManager::getInstance();
        
        // Note: Tracing might be disabled by default in tests
        if (!$tracer->isEnabled()) {
            $this->markTestSkipped('Tracing is disabled');
        }

        // Start interaction span
        $interactionSpan = $tracer->startInteractionSpan('test_interaction', [
            'user_id' => 'test_user',
        ]);

        $this->assertNotNull($interactionSpan);
        $this->assertEquals(1, $tracer->getActiveSpansCount());

        // Start LLM request span
        $llmSpan = $tracer->startLLMRequestSpan('claude-3-sonnet', [
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertNotNull($llmSpan);
        $this->assertEquals(2, $tracer->getActiveSpansCount());

        // End spans
        $tracer->endSpan($llmSpan, ['response' => 'Hi there']);
        $this->assertEquals(1, $tracer->getActiveSpansCount());

        $tracer->endSpan($interactionSpan);
        $this->assertEquals(0, $tracer->getActiveSpansCount());
    }

    /**
     * Test MetricsCollector counter metrics.
     */
    public function testMetricsCollectorCounters(): void
    {
        $metrics = MetricsCollector::getInstance();
        
        // Test counter increment
        $metrics->incrementCounter('test.counter', 1, ['label' => 'value']);
        $metrics->incrementCounter('test.counter', 2, ['label' => 'value']);
        
        $stats = $metrics->getStatistics();
        $this->assertArrayHasKey('counters', $stats);
        
        $counterKey = 'test.counter{label=value}';
        $this->assertEquals(3, $stats['counters'][$counterKey] ?? 0);
    }

    /**
     * Test MetricsCollector gauge metrics.
     */
    public function testMetricsCollectorGauges(): void
    {
        $metrics = MetricsCollector::getInstance();
        
        // Test gauge setting
        $metrics->setGauge('test.gauge', 42.5, ['type' => 'test']);
        $metrics->setGauge('test.gauge', 50.0, ['type' => 'test']); // Should overwrite
        
        $stats = $metrics->getStatistics();
        $this->assertArrayHasKey('gauges', $stats);
        
        $gaugeKey = 'test.gauge{type=test}';
        $this->assertEquals(50.0, $stats['gauges'][$gaugeKey] ?? 0);
    }

    /**
     * Test MetricsCollector histogram metrics.
     */
    public function testMetricsCollectorHistograms(): void
    {
        $metrics = MetricsCollector::getInstance();
        
        // Record histogram values
        for ($i = 1; $i <= 10; $i++) {
            $metrics->recordHistogram('test.histogram', $i * 10);
        }
        
        $stats = $metrics->getStatistics('test.histogram');
        
        $this->assertEquals(10, $stats['count']);
        $this->assertEquals(10, $stats['min']);
        $this->assertEquals(100, $stats['max']);
        $this->assertEquals(55, $stats['mean']);
        $this->assertEquals(550, $stats['sum']);
    }

    /**
     * Test LLM request metrics.
     */
    public function testLLMRequestMetrics(): void
    {
        $metrics = MetricsCollector::getInstance();
        
        $metrics->recordLLMRequest(
            model: 'claude-3-sonnet',
            inputTokens: 100,
            outputTokens: 200,
            duration: 1500.0,
            success: true
        );
        
        $stats = $metrics->getStatistics();
        
        // Check counters
        $this->assertArrayHasKey('counters', $stats);
        $this->assertTrue(isset($stats['counters']['llm.requests{model=claude-3-sonnet,success=true}']));
        $this->assertEquals(1, $stats['counters']['llm.requests{model=claude-3-sonnet,success=true}']);
        
        // Check token counts
        $this->assertEquals(100, $stats['counters']['llm.input_tokens{model=claude-3-sonnet,success=true}']);
        $this->assertEquals(200, $stats['counters']['llm.output_tokens{model=claude-3-sonnet,success=true}']);
        $this->assertEquals(300, $stats['counters']['llm.total_tokens{model=claude-3-sonnet,success=true}']);
    }

    /**
     * Test StructuredLogger.
     */
    public function testStructuredLogger(): void
    {
        $logger = StructuredLogger::getInstance();
        
        $this->assertTrue($logger->isEnabled());
        
        // Set global context
        $logger->setGlobalContext([
            'app_version' => '1.0.0',
            'environment' => 'testing',
        ]);
        
        // Test session and request IDs
        $logger->setSessionId('test_session_123');
        $logger->setRequestId('test_request_456');
        
        // Test logging methods (they write to Laravel log)
        $logger->logSessionStart(['user_id' => 'test_user']);
        
        $logger->logLLMRequest(
            model: 'claude-3-sonnet',
            messages: [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            response: ['content' => 'Hi there'],
            duration: 1234.5
        );
        
        $logger->logToolExecution(
            toolName: 'file_read',
            input: ['path' => '/test/file.txt'],
            result: 'File content',
            duration: 50.0,
            success: true
        );
        
        $logger->logError('Test error', new \Exception('Test exception'));
        
        $logger->logPerformance('test_operation', 123.45, ['extra' => 'data']);
        
        $logger->logSessionEnd(['total_requests' => 5]);
        
        // No assertions for log output, but verify methods execute without error
        $this->assertTrue(true);
    }

    /**
     * Test CostTracker for LLM costs.
     */
    public function testCostTrackerLLM(): void
    {
        $tracker = CostTracker::getInstance();
        
        $this->assertTrue($tracker->isEnabled());
        
        // Track LLM usage
        $cost = $tracker->trackLLMUsage(
            model: 'claude-3-sonnet',
            inputTokens: 1000,
            outputTokens: 2000,
            sessionId: 'test_session'
        );
        
        // Claude-3-sonnet: $3/1M input, $15/1M output
        $expectedCost = (1000 / 1_000_000 * 3.0) + (2000 / 1_000_000 * 15.0);
        $this->assertEquals(round($expectedCost, 6), $cost);
        
        // Track more usage
        $tracker->trackLLMUsage(
            model: 'gpt-4',
            inputTokens: 500,
            outputTokens: 1000,
            sessionId: 'test_session'
        );
        
        // Get session costs
        $sessionCosts = $tracker->getSessionCosts('test_session');
        $this->assertGreaterThan(0, $sessionCosts['total_cost']);
        $this->assertEquals(1500, $sessionCosts['total_input_tokens']);
        $this->assertEquals(3000, $sessionCosts['total_output_tokens']);
        $this->assertEquals(2, $sessionCosts['request_count']);
        $this->assertContains('claude-3-sonnet', $sessionCosts['models_used']);
        $this->assertContains('gpt-4', $sessionCosts['models_used']);
    }

    /**
     * Test CostTracker summary.
     */
    public function testCostTrackerSummary(): void
    {
        $tracker = CostTracker::getInstance();
        
        // Track various costs
        $tracker->trackLLMUsage('claude-3-haiku', 10000, 5000, 'session1');
        $tracker->trackLLMUsage('claude-3-sonnet', 5000, 10000, 'session2');
        $tracker->trackToolUsage('web_search', 100, 'session1');
        
        $summary = $tracker->getCostSummary();
        
        $this->assertArrayHasKey('total_cost', $summary);
        $this->assertArrayHasKey('by_type', $summary);
        $this->assertArrayHasKey('by_model', $summary);
        $this->assertArrayHasKey('record_count', $summary);
        
        $this->assertGreaterThan(0, $summary['total_cost']);
        $this->assertArrayHasKey('llm', $summary['by_type']);
        $this->assertEquals(2, $summary['by_type']['llm']['count']);
    }

    /**
     * Test model pricing.
     */
    public function testModelPricing(): void
    {
        $tracker = CostTracker::getInstance();
        
        $pricing = $tracker->getModelPricing();
        $this->assertArrayHasKey('claude-3-opus', $pricing);
        $this->assertArrayHasKey('gpt-4', $pricing);
        
        // Update pricing
        $tracker->updateModelPricing('custom-model', 5.0, 10.0);
        
        $cost = $tracker->trackLLMUsage('custom-model', 1000, 1000);
        $expectedCost = (1000 / 1_000_000 * 5.0) + (1000 / 1_000_000 * 10.0);
        $this->assertEquals(round($expectedCost, 6), $cost);
    }

    /**
     * Test EventDispatcher.
     */
    public function testEventDispatcher(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        
        $this->assertTrue($dispatcher->isEnabled());
        
        $receivedData = null;
        
        // Register listener
        $listenerId = $dispatcher->listen('test.event', function ($data) use (&$receivedData) {
            $receivedData = $data;
        });
        
        // Dispatch event
        $dispatcher->dispatch('test.event', ['key' => 'value']);
        
        $this->assertNotNull($receivedData);
        $this->assertEquals(['key' => 'value'], $receivedData);
        
        // Test event history
        $history = $dispatcher->getHistory('test.event');
        $this->assertCount(1, $history);
        $this->assertEquals('test.event', $history->first()['event']);
        
        // Remove listener
        $removed = $dispatcher->removeListener($listenerId);
        $this->assertTrue($removed);
        
        // Test listener count
        $this->assertEquals(0, $dispatcher->getListenerCount('test.event'));
    }

    /**
     * Test EventDispatcher standard events.
     */
    public function testEventDispatcherStandardEvents(): void
    {
        $dispatcher = EventDispatcher::getInstance();
        
        $events = [];
        
        // Register wildcard listener
        $dispatcher->listen('*', function ($event, $data) use (&$events) {
            $events[] = ['event' => $event, 'data' => $data];
        });
        
        // Dispatch standard events
        $dispatcher->dispatchSessionStart('session123', ['user' => 'test']);
        $dispatcher->dispatchLLMRequest('claude-3', ['messages' => []]);
        $dispatcher->dispatchToolStart('file_read', ['path' => '/test']);
        $dispatcher->dispatchToolComplete('file_read', 'content', 50.0);
        $dispatcher->dispatchError('Test error');
        $dispatcher->dispatchMetric('test.metric', 42.0, ['label' => 'value']);
        $dispatcher->dispatchSessionEnd('session123', ['total' => 10]);
        
        $this->assertCount(7, $events);
        
        $eventNames = array_column($events, 'event');
        $this->assertContains('session.started', $eventNames);
        $this->assertContains('llm.request', $eventNames);
        $this->assertContains('tool.started', $eventNames);
        $this->assertContains('tool.completed', $eventNames);
        $this->assertContains('error', $eventNames);
        $this->assertContains('metric', $eventNames);
        $this->assertContains('session.ended', $eventNames);
    }

    /**
     * Test FileSpanExporter.
     */
    public function testFileSpanExporter(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_spans_' . uniqid() . '.jsonl';
        
        try {
            $exporter = new FileSpanExporter($tempFile);
            
            // Verify file can be created
            $this->assertInstanceOf(FileSpanExporter::class, $exporter);
            
            // Test shutdown
            $shutdown = $exporter->shutdown();
            $this->assertTrue($shutdown);
            
            // Test force flush
            $flushed = $exporter->forceFlush();
            $this->assertTrue($flushed);
            
        } finally {
            // Clean up
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Test cost export.
     */
    public function testCostExport(): void
    {
        $tracker = CostTracker::getInstance();
        
        // Add some costs
        $tracker->trackLLMUsage('claude-3-sonnet', 1000, 2000, 'session1');
        $tracker->trackLLMUsage('gpt-4', 500, 1000, 'session2');
        
        // Export as JSON
        $json = $tracker->export('json');
        $this->assertJson($json);
        
        $data = json_decode($json, true);
        $this->assertArrayHasKey('export_timestamp', $data);
        $this->assertArrayHasKey('costs', $data);
        $this->assertArrayHasKey('session_costs', $data);
        $this->assertArrayHasKey('summary', $data);
        
        // Export as CSV
        $csv = $tracker->export('csv');
        $this->assertStringContainsString('Timestamp,Type,Model/Tool', $csv);
        $this->assertStringContainsString('claude-3-sonnet', $csv);
        $this->assertStringContainsString('gpt-4', $csv);
    }
}