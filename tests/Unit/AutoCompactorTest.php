<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Harness\AutoCompactor;
use SuperAgent\Harness\CompactionEvent;
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Messages\ToolResultMessage;

class AutoCompactorTest extends TestCase
{
    // ── Construction ──────────────────────────────────────────────

    public function testDefaultEnabled(): void
    {
        $compactor = new AutoCompactor();
        $this->assertTrue($compactor->isEnabled());
    }

    public function testDisabledByConfig(): void
    {
        $compactor = new AutoCompactor(enabled: false);
        $this->assertFalse($compactor->isEnabled());
    }

    public function testSetEnabled(): void
    {
        $compactor = new AutoCompactor(enabled: false);
        $compactor->setEnabled(true);
        $this->assertTrue($compactor->isEnabled());
    }

    // ── Micro-compact ─────────────────────────────────────────────

    public function testMicroCompactTruncatesOldToolResults(): void
    {
        $compactor = new AutoCompactor(
            preserveRecentResults: 1,
            truncateLength: 20,
        );

        $messages = [
            new UserMessage('query'),
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'old1', 'content' => str_repeat('A', 500)],
            ]),
            new UserMessage('another query'),
            ToolResultMessage::fromResults([
                ['tool_use_id' => 'recent', 'content' => str_repeat('B', 500)],
            ]),
        ];

        $saved = $compactor->microCompact($messages);

        $this->assertGreaterThan(0, $saved);

        // Old result (index 1) should be truncated
        $oldContent = $messages[1]->content[0]->content;
        $this->assertStringContainsString('[...content cleared by auto-compact...]', $oldContent);
        $this->assertLessThan(500, strlen($oldContent));

        // Recent result (index 3) should be untouched
        $recentContent = $messages[3]->content[0]->content;
        $this->assertEquals(500, strlen($recentContent));
    }

    public function testMicroCompactPreservesRecentResults(): void
    {
        $compactor = new AutoCompactor(
            preserveRecentResults: 5,
            truncateLength: 20,
        );

        // Only 3 tool results — all should be preserved (< 5)
        $messages = [
            ToolResultMessage::fromResults([['tool_use_id' => 'a', 'content' => str_repeat('X', 100)]]),
            ToolResultMessage::fromResults([['tool_use_id' => 'b', 'content' => str_repeat('Y', 100)]]),
            ToolResultMessage::fromResults([['tool_use_id' => 'c', 'content' => str_repeat('Z', 100)]]),
        ];

        $saved = $compactor->microCompact($messages);
        $this->assertEquals(0, $saved);
    }

    public function testMicroCompactSkipsShortContent(): void
    {
        $compactor = new AutoCompactor(
            preserveRecentResults: 1,
            truncateLength: 200,
        );

        $messages = [
            ToolResultMessage::fromResults([['tool_use_id' => 'short', 'content' => 'OK']]),
            ToolResultMessage::fromResults([['tool_use_id' => 'recent', 'content' => 'OK']]),
        ];

        $saved = $compactor->microCompact($messages);
        $this->assertEquals(0, $saved);
    }

    public function testMicroCompactEmptyMessages(): void
    {
        $compactor = new AutoCompactor();
        $messages = [];
        $saved = $compactor->microCompact($messages);
        $this->assertEquals(0, $saved);
    }

    // ── maybeCompact ──────────────────────────────────────────────

    public function testMaybeCompactReturnsFalseWhenDisabled(): void
    {
        $compactor = new AutoCompactor(enabled: false);
        $messages = [];
        $this->assertFalse($compactor->maybeCompact($messages));
    }

    public function testMaybeCompactReturnsFalseWhenBelowThreshold(): void
    {
        $compactor = new AutoCompactor();
        // Small message set — below threshold
        $messages = [new UserMessage('hi')];
        $this->assertFalse($compactor->maybeCompact($messages));
    }

    public function testMaybeCompactStopsAfterMaxFailures(): void
    {
        $compactor = new AutoCompactor(maxFailures: 2);

        // Simulate failures by reading the counter
        $reflection = new \ReflectionClass($compactor);
        $prop = $reflection->getProperty('failures');
        $prop->setAccessible(true);
        $prop->setValue($compactor, 2);

        $messages = [];
        $this->assertFalse($compactor->maybeCompact($messages));
    }

    // ── Token tracking ────────────────────────────────────────────

    public function testTotalTokensSavedTracked(): void
    {
        $compactor = new AutoCompactor(
            preserveRecentResults: 1,
            truncateLength: 10,
        );

        $this->assertEquals(0, $compactor->getTotalTokensSaved());

        // Force micro-compact by calling directly
        $messages = [
            ToolResultMessage::fromResults([['tool_use_id' => 'a', 'content' => str_repeat('X', 1000)]]),
            ToolResultMessage::fromResults([['tool_use_id' => 'b', 'content' => 'recent']]),
        ];

        $compactor->microCompact($messages);
        // totalTokensSaved not updated by microCompact directly (only by maybeCompact)
        // but let's verify failure counting
        $this->assertEquals(0, $compactor->getFailureCount());
    }

    public function testResetFailures(): void
    {
        $compactor = new AutoCompactor();

        $reflection = new \ReflectionClass($compactor);
        $prop = $reflection->getProperty('failures');
        $prop->setAccessible(true);
        $prop->setValue($compactor, 5);

        $compactor->resetFailures();
        $this->assertEquals(0, $compactor->getFailureCount());
    }

    // ── fromConfig override pattern ───────────────────────────────

    public function testFromConfigOverrideEnabled(): void
    {
        // Config defaults to enabled=true; override to false
        $compactor = AutoCompactor::fromConfig(overrides: ['enabled' => false]);
        $this->assertFalse($compactor->isEnabled());
    }

    public function testFromConfigOverrideForceEnabled(): void
    {
        $compactor = AutoCompactor::fromConfig(overrides: ['enabled' => true]);
        $this->assertTrue($compactor->isEnabled());
    }

    public function testFromConfigOverrideTruncateLength(): void
    {
        $compactor = AutoCompactor::fromConfig(overrides: [
            'truncate_length' => 50,
            'preserve_recent_results' => 1,
        ]);

        // Verify by running micro-compact with a known length
        $messages = [
            ToolResultMessage::fromResults([['tool_use_id' => 'a', 'content' => str_repeat('X', 200)]]),
            ToolResultMessage::fromResults([['tool_use_id' => 'b', 'content' => 'recent']]),
        ];

        $saved = $compactor->microCompact($messages);
        $this->assertGreaterThan(0, $saved);

        // Truncated content should be around 50 chars + suffix
        $content = $messages[0]->content[0]->content;
        $this->assertLessThan(200, strlen($content));
    }

    // ── Model setter ──────────────────────────────────────────────

    public function testSetModel(): void
    {
        $compactor = new AutoCompactor(model: 'claude-sonnet-4-6');
        $compactor->setModel('claude-haiku-4-5');

        // Access via reflection to verify
        $reflection = new \ReflectionClass($compactor);
        $prop = $reflection->getProperty('model');
        $prop->setAccessible(true);
        $this->assertEquals('claude-haiku-4-5', $prop->getValue($compactor));
    }

    // ── Event emission ────────────────────────────────────────────

    public function testCompactionEventsEmitted(): void
    {
        $emitter = new StreamEventEmitter(recordHistory: true);

        // Create a compactor with a mocked estimator that always says "compact needed"
        $mockEstimator = $this->createMock(TokenEstimator::class);
        $mockEstimator->method('shouldAutoCompact')->willReturn(true);
        $mockEstimator->method('estimateMessagesTokens')->willReturn(200000);

        $compactor = new AutoCompactor(
            estimator: $mockEstimator,
            emitter: $emitter,
            preserveRecentResults: 1,
            truncateLength: 10,
        );

        $messages = [
            ToolResultMessage::fromResults([['tool_use_id' => 'a', 'content' => str_repeat('X', 1000)]]),
            ToolResultMessage::fromResults([['tool_use_id' => 'b', 'content' => 'recent']]),
        ];

        $compactor->maybeCompact($messages);

        $history = $emitter->getHistory();
        $this->assertNotEmpty($history);

        $compactionEvents = array_filter($history, fn($e) => $e instanceof CompactionEvent);
        $this->assertNotEmpty($compactionEvents);

        $event = reset($compactionEvents);
        $this->assertEquals('micro', $event->tier);
        $this->assertGreaterThan(0, $event->tokensSaved);
    }
}
