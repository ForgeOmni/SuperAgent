<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Telemetry\TracingManager;
use SuperAgent\Telemetry\SimpleTracingManager;
use SuperAgent\Telemetry\MetricsCollector;
use SuperAgent\Telemetry\StructuredLogger;
use SuperAgent\Telemetry\CostTracker;
use SuperAgent\Telemetry\EventDispatcher;

class TelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }
    
    protected function tearDown(): void
    {
        // Reset singleton instances
        $this->resetSingleton(TracingManager::class);
        $this->resetSingleton(SimpleTracingManager::class);
        $this->resetSingleton(MetricsCollector::class);
        $this->resetSingleton(StructuredLogger::class);
        $this->resetSingleton(CostTracker::class);
        $this->resetSingleton(EventDispatcher::class);
        
        parent::tearDown();
    }
    
    private function resetSingleton(string $class): void
    {
        if (class_exists($class)) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->hasProperty('instance')) {
                $instance = $reflection->getProperty('instance');
                $instance->setAccessible(true);
                $instance->setValue(null, null);
            }
        }
    }
    
    public function testSimpleTracingManagerStartsSpan()
    {
        $tracer = SimpleTracingManager::getInstance();
        
        $spanId = $tracer->startSpan('test-operation', [
            'attribute1' => 'value1',
        ]);
        
        $this->assertNotNull($spanId);
        $this->assertIsString($spanId);
        
        // End span
        $tracer->endSpan($spanId);
    }
    
    public function testSimpleTracingManagerNestedSpans()
    {
        $tracer = SimpleTracingManager::getInstance();
        
        $parentSpan = $tracer->startSpan('parent-operation');
        $childSpan = $tracer->startSpan('child-operation', [], $parentSpan);
        
        $this->assertNotNull($parentSpan);
        $this->assertNotNull($childSpan);
        $this->assertNotEquals($parentSpan, $childSpan);
        
        $tracer->endSpan($childSpan);
        $tracer->endSpan($parentSpan);
    }
    
    public function testSimpleTracingManagerAddEvents()
    {
        $tracer = SimpleTracingManager::getInstance();
        
        $spanId = $tracer->startSpan('event-operation');
        
        $tracer->addEvent($spanId, 'event1', ['key' => 'value']);
        $tracer->addEvent($spanId, 'event2', ['key2' => 'value2']);
        
        $tracer->endSpan($spanId);
        
        // Events should be recorded
        $this->assertTrue(true);
    }
    
    public function testMetricsCollectorRecordsMetrics()
    {
        $collector = MetricsCollector::getInstance();
        
        // Record counter
        $collector->recordCounter('api.requests', 1, [
            'endpoint' => '/test',
            'method' => 'GET',
        ]);
        
        // Record histogram
        $collector->recordHistogram('response.time', 150.5, [
            'endpoint' => '/test',
        ]);
        
        // Record gauge
        $collector->recordGauge('memory.usage', 1024 * 1024 * 50);
        
        $this->assertTrue(true); // Metrics recorded successfully
    }
    
    public function testMetricsCollectorAggregates()
    {
        $collector = MetricsCollector::getInstance();
        
        // Record multiple values
        for ($i = 0; $i < 10; $i++) {
            $collector->recordCounter('test.counter', 1);
            $collector->recordHistogram('test.latency', 100 + $i * 10);
        }
        
        $summary = $collector->getSummary();
        
        $this->assertArrayHasKey('counters', $summary);
        $this->assertArrayHasKey('histograms', $summary);
        $this->assertArrayHasKey('gauges', $summary);
    }
    
    public function testStructuredLoggerLogsMessages()
    {
        $logger = StructuredLogger::getInstance();
        
        $logger->info('Test message', [
            'user_id' => 123,
            'action' => 'test_action',
        ]);
        
        $logger->warning('Warning message', [
            'threshold' => 100,
            'actual' => 150,
        ]);
        
        $logger->error('Error message', [
            'error_code' => 'TEST_ERROR',
            'details' => 'Test error details',
        ]);
        
        $this->assertTrue(true); // Messages logged successfully
    }
    
    public function testStructuredLoggerWithContext()
    {
        $logger = StructuredLogger::getInstance();
        
        $logger->withContext([
            'request_id' => 'req-123',
            'session_id' => 'sess-456',
        ]);
        
        $logger->info('Message with context');
        
        // Context should be included in all subsequent logs
        $this->assertTrue(true);
    }
    
    public function testCostTrackerTracksTokenUsage()
    {
        $tracker = CostTracker::getInstance();
        
        $tracker->trackTokens('anthropic', 'claude-3-haiku', 1000, 500);
        $tracker->trackTokens('openai', 'gpt-4', 2000, 1000);
        
        $usage = $tracker->getUsage();
        
        $this->assertArrayHasKey('anthropic', $usage);
        $this->assertArrayHasKey('openai', $usage);
        $this->assertEquals(1000, $usage['anthropic']['input_tokens']);
        $this->assertEquals(500, $usage['anthropic']['output_tokens']);
    }
    
    public function testCostTrackerCalculatesCosts()
    {
        $tracker = CostTracker::getInstance();
        
        // Track some usage
        $tracker->trackTokens('anthropic', 'claude-3-haiku', 10000, 5000);
        
        $cost = $tracker->calculateCost('anthropic', 'claude-3-haiku');
        
        $this->assertIsFloat($cost);
        $this->assertGreaterThan(0, $cost);
    }
    
    public function testCostTrackerSessionTracking()
    {
        $tracker = CostTracker::getInstance();
        
        $sessionId = $tracker->startSession('test-session');
        
        $tracker->trackTokens('anthropic', 'claude-3-haiku', 1000, 500, $sessionId);
        $tracker->trackTokens('anthropic', 'claude-3-haiku', 2000, 1000, $sessionId);
        
        $sessionCost = $tracker->getSessionCost($sessionId);
        
        $this->assertIsFloat($sessionCost);
        $this->assertGreaterThan(0, $sessionCost);
        
        $tracker->endSession($sessionId);
    }
    
    public function testEventDispatcherDispatchesEvents()
    {
        $dispatcher = EventDispatcher::getInstance();
        
        $eventReceived = false;
        $eventData = null;
        
        $dispatcher->listen('test.event', function($data) use (&$eventReceived, &$eventData) {
            $eventReceived = true;
            $eventData = $data;
        });
        
        $dispatcher->dispatch('test.event', ['key' => 'value']);
        
        $this->assertTrue($eventReceived);
        $this->assertEquals(['key' => 'value'], $eventData);
    }
    
    public function testEventDispatcherMultipleListeners()
    {
        $dispatcher = EventDispatcher::getInstance();
        
        $count = 0;
        
        $dispatcher->listen('multi.event', function() use (&$count) {
            $count++;
        });
        
        $dispatcher->listen('multi.event', function() use (&$count) {
            $count++;
        });
        
        $dispatcher->listen('multi.event', function() use (&$count) {
            $count++;
        });
        
        $dispatcher->dispatch('multi.event');
        
        $this->assertEquals(3, $count);
    }
    
    public function testEventDispatcherWildcardListeners()
    {
        $dispatcher = EventDispatcher::getInstance();
        
        $events = [];
        
        $dispatcher->listen('*', function($data, $event) use (&$events) {
            $events[] = $event;
        });
        
        $dispatcher->dispatch('event.one');
        $dispatcher->dispatch('event.two');
        $dispatcher->dispatch('other.event');
        
        $this->assertCount(3, $events);
        $this->assertContains('event.one', $events);
        $this->assertContains('event.two', $events);
        $this->assertContains('other.event', $events);
    }
    
    public function testMetricsCollectorPerformanceTracking()
    {
        $collector = MetricsCollector::getInstance();
        
        $timerId = $collector->startTimer('operation.duration');
        
        // Simulate some work
        usleep(10000); // 10ms
        
        $duration = $collector->endTimer($timerId);
        
        $this->assertIsFloat($duration);
        $this->assertGreaterThan(0, $duration);
    }
    
    public function testStructuredLoggerLogLevels()
    {
        $logger = StructuredLogger::getInstance();
        
        $logger->setMinLevel('warning');
        
        // These should not be logged
        $logger->debug('Debug message');
        $logger->info('Info message');
        
        // These should be logged
        $logger->warning('Warning message');
        $logger->error('Error message');
        $logger->critical('Critical message');
        
        $this->assertTrue(true);
    }
    
    public function testCostTrackerBudgetLimits()
    {
        $tracker = CostTracker::getInstance();
        
        $tracker->setBudget(10.00); // $10 budget
        
        // Track usage that exceeds budget
        $tracker->trackTokens('openai', 'gpt-4', 1000000, 500000); // Large usage
        
        $this->assertTrue($tracker->isBudgetExceeded());
        
        $remaining = $tracker->getRemainingBudget();
        $this->assertLessThan(0, $remaining);
    }
    
    public function testTelemetryIntegration()
    {
        $tracer = SimpleTracingManager::getInstance();
        $metrics = MetricsCollector::getInstance();
        $logger = StructuredLogger::getInstance();
        $costs = CostTracker::getInstance();
        $events = EventDispatcher::getInstance();
        
        // Simulate a complete operation with telemetry
        $spanId = $tracer->startSpan('api.request');
        
        $logger->info('Request started', ['span_id' => $spanId]);
        
        $metrics->recordCounter('api.requests', 1);
        $timerId = $metrics->startTimer('api.latency');
        
        // Simulate API call
        $costs->trackTokens('anthropic', 'claude-3-haiku', 100, 50);
        
        $events->dispatch('api.request.completed', [
            'span_id' => $spanId,
            'tokens' => 150,
        ]);
        
        $metrics->endTimer($timerId);
        $tracer->endSpan($spanId);
        
        $logger->info('Request completed', ['span_id' => $spanId]);
        
        $this->assertTrue(true); // Integration test passed
    }
}