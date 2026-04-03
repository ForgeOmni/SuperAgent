<?php

namespace SuperAgent\Tests\Unit\Checkpoint;

use PHPUnit\Framework\TestCase;
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\CheckpointStore;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\UserMessage;

class CheckpointManagerTest extends TestCase
{
    private string $tempDir;
    private CheckpointStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cp_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->store = new CheckpointStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->tempDir}/*.json") as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function makeMessages(): array
    {
        $assistant = new AssistantMessage();
        $assistant->content = [ContentBlock::text('Working on it')];

        return [
            new UserMessage('Fix the bug'),
            $assistant,
        ];
    }

    // ── Enable/Disable ─────────────────────────────────────────────

    public function test_enabled_by_config(): void
    {
        $mgr = new CheckpointManager($this->store, configEnabled: true);
        $this->assertTrue($mgr->isEnabled());

        $mgr2 = new CheckpointManager($this->store, configEnabled: false);
        $this->assertFalse($mgr2->isEnabled());
    }

    public function test_force_override_takes_precedence(): void
    {
        $mgr = new CheckpointManager($this->store, configEnabled: false);
        $mgr->setForceEnabled(true);
        $this->assertTrue($mgr->isEnabled());

        $mgr2 = new CheckpointManager($this->store, configEnabled: true);
        $mgr2->setForceEnabled(false);
        $this->assertFalse($mgr2->isEnabled());
    }

    public function test_force_null_falls_back_to_config(): void
    {
        $mgr = new CheckpointManager($this->store, configEnabled: true);
        $mgr->setForceEnabled(true);
        $this->assertTrue($mgr->isEnabled());

        $mgr->setForceEnabled(null);
        $this->assertTrue($mgr->isEnabled()); // Falls back to config=true
    }

    // ── maybeCheckpoint ────────────────────────────────────────────

    public function test_maybe_checkpoint_at_interval(): void
    {
        $mgr = new CheckpointManager($this->store, interval: 3, configEnabled: true);
        $messages = $this->makeMessages();

        $this->assertNull($mgr->maybeCheckpoint('sess1', $messages, 1, 0.1, 100, 'opus', 'fix'));
        $this->assertNull($mgr->maybeCheckpoint('sess1', $messages, 2, 0.2, 200, 'opus', 'fix'));
        $cp = $mgr->maybeCheckpoint('sess1', $messages, 3, 0.3, 300, 'opus', 'fix');
        $this->assertNotNull($cp);
        $this->assertSame(3, $cp->turnCount);

        $this->assertNull($mgr->maybeCheckpoint('sess1', $messages, 4, 0.4, 400, 'opus', 'fix'));
        $this->assertNull($mgr->maybeCheckpoint('sess1', $messages, 5, 0.5, 500, 'opus', 'fix'));
        $cp2 = $mgr->maybeCheckpoint('sess1', $messages, 6, 0.6, 600, 'opus', 'fix');
        $this->assertNotNull($cp2);
    }

    public function test_maybe_checkpoint_disabled(): void
    {
        $mgr = new CheckpointManager($this->store, interval: 1, configEnabled: false);
        $messages = $this->makeMessages();

        $cp = $mgr->maybeCheckpoint('sess1', $messages, 1, 0.1, 100, 'opus', 'fix');
        $this->assertNull($cp);
    }

    public function test_maybe_checkpoint_skips_turn_zero(): void
    {
        $mgr = new CheckpointManager($this->store, interval: 1, configEnabled: true);
        $this->assertNull($mgr->maybeCheckpoint('s', [], 0, 0, 0, 'm', 'p'));
    }

    // ── createCheckpoint ───────────────────────────────────────────

    public function test_create_checkpoint(): void
    {
        $mgr = new CheckpointManager($this->store, configEnabled: true);
        $messages = $this->makeMessages();

        $cp = $mgr->createCheckpoint('sess1', $messages, 5, 0.50, 500, 'opus', 'fix the bug');

        $this->assertSame('sess1', $cp->sessionId);
        $this->assertSame(5, $cp->turnCount);
        $this->assertSame(0.50, $cp->totalCostUsd);
        $this->assertSame('fix the bug', $cp->prompt);
        $this->assertCount(2, $cp->messages); // Serialized messages
    }

    // ── Resume ─────────────────────────────────────────────────────

    public function test_resume_from_checkpoint(): void
    {
        $mgr = new CheckpointManager($this->store, configEnabled: true);
        $messages = $this->makeMessages();

        $cp = $mgr->createCheckpoint('sess1', $messages, 5, 0.50, 500, 'opus', 'fix');

        $state = $mgr->resume($cp->id);
        $this->assertNotNull($state);
        $this->assertCount(2, $state['messages']);
        $this->assertSame(5, $state['turnCount']);
        $this->assertSame(0.50, $state['totalCostUsd']);
        $this->assertSame('opus', $state['model']);

        // Verify deserialized message types
        $this->assertInstanceOf(UserMessage::class, $state['messages'][0]);
        $this->assertInstanceOf(AssistantMessage::class, $state['messages'][1]);
    }

    public function test_resume_nonexistent(): void
    {
        $mgr = new CheckpointManager($this->store, configEnabled: true);
        $this->assertNull($mgr->resume('nonexistent'));
    }

    // ── getLatest ──────────────────────────────────────────────────

    public function test_get_latest(): void
    {
        $mgr = new CheckpointManager($this->store, interval: 1, maxPerSession: 10, configEnabled: true);
        $messages = $this->makeMessages();

        $mgr->createCheckpoint('sess1', $messages, 1, 0.1, 100, 'opus', 'fix');
        $mgr->createCheckpoint('sess1', $messages, 2, 0.2, 200, 'opus', 'fix');

        $latest = $mgr->getLatest('sess1');
        $this->assertNotNull($latest);
        // Latest should be turn 2 (newest)
        $this->assertSame(2, $latest->turnCount);
    }

    // ── List / Delete / Clear ──────────────────────────────────────

    public function test_list(): void
    {
        $mgr = new CheckpointManager($this->store, maxPerSession: 10, configEnabled: true);
        $mgr->createCheckpoint('s1', [], 1, 0, 0, 'm', 'p');
        $mgr->createCheckpoint('s2', [], 2, 0, 0, 'm', 'p');

        $this->assertCount(2, $mgr->list());
        $this->assertCount(1, $mgr->list('s1'));
    }

    public function test_delete(): void
    {
        $mgr = new CheckpointManager($this->store, maxPerSession: 10, configEnabled: true);
        $cp = $mgr->createCheckpoint('s1', [], 1, 0, 0, 'm', 'p');

        $this->assertTrue($mgr->delete($cp->id));
        $this->assertNull($mgr->show($cp->id));
        $this->assertFalse($mgr->delete('nonexistent'));
    }

    public function test_clear(): void
    {
        $mgr = new CheckpointManager($this->store, maxPerSession: 10, configEnabled: true);
        $mgr->createCheckpoint('s1', [], 1, 0, 0, 'm', 'p');
        $mgr->createCheckpoint('s1', [], 2, 0, 0, 'm', 'p');

        $count = $mgr->clear('s1');
        $this->assertSame(2, $count);
        $this->assertEmpty($mgr->list());
    }

    // ── Prune ──────────────────────────────────────────────────────

    public function test_auto_prune_on_checkpoint(): void
    {
        $mgr = new CheckpointManager($this->store, interval: 1, maxPerSession: 2, configEnabled: true);

        $mgr->createCheckpoint('s1', [], 1, 0, 0, 'm', 'p');
        $mgr->createCheckpoint('s1', [], 2, 0, 0, 'm', 'p');
        $mgr->createCheckpoint('s1', [], 3, 0, 0, 'm', 'p');

        // Only 2 should remain (maxPerSession=2)
        $all = $mgr->list('s1');
        $this->assertCount(2, $all);
    }

    // ── Statistics ─────────────────────────────────────────────────

    public function test_statistics(): void
    {
        $mgr = new CheckpointManager($this->store, maxPerSession: 10, configEnabled: true);
        $mgr->createCheckpoint('s1', $this->makeMessages(), 1, 0.1, 100, 'm', 'p');
        $mgr->createCheckpoint('s2', $this->makeMessages(), 1, 0.1, 100, 'm', 'p');

        $stats = $mgr->getStatistics();
        $this->assertSame(2, $stats['total_checkpoints']);
        $this->assertSame(2, $stats['total_sessions']);
        $this->assertGreaterThan(0, $stats['total_size_bytes']);
    }

    // ── Interval getter ────────────────────────────────────────────

    public function test_get_interval(): void
    {
        $mgr = new CheckpointManager($this->store, interval: 7);
        $this->assertSame(7, $mgr->getInterval());
    }
}
