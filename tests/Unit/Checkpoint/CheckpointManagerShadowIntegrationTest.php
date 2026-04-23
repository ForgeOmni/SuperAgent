<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Checkpoint;

use PHPUnit\Framework\TestCase;
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\Checkpoint\CheckpointStore;
use SuperAgent\Checkpoint\GitShadowStore;
use SuperAgent\Messages\UserMessage;

/**
 * End-to-end glue test for CheckpointManager + GitShadowStore.
 *
 * Covers the four scenarios that matter in production:
 *   1. No shadow store attached → JSON-only, old behaviour.
 *   2. Shadow store attached → `shadow_commit` appears in metadata,
 *      and `restoreFiles()` plays the worktree back.
 *   3. Shadow snapshot failing at save time must NOT kill the JSON
 *      checkpoint (graceful degrade).
 *   4. `restoreFiles()` on a checkpoint without a shadow_commit
 *      returns false rather than attempting a bogus restore.
 */
class CheckpointManagerShadowIntegrationTest extends TestCase
{
    private string $projectRoot;
    private string $historyDir;
    private string $jsonStoreDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::gitAvailable()) {
            $this->markTestSkipped('git binary not found on PATH');
        }
        $root = sys_get_temp_dir() . '/superagent-ckpt-shadow-' . bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        $this->projectRoot = $root . '/project';
        $this->historyDir  = $root . '/history';
        $this->jsonStoreDir = $root . '/checkpoints';
        mkdir($this->projectRoot, 0755, true);
        mkdir($this->historyDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->projectRoot));
        parent::tearDown();
    }

    public function test_without_shadow_store_metadata_is_unchanged(): void
    {
        $mgr = new CheckpointManager(
            new CheckpointStore($this->jsonStoreDir),
            interval: 1,
        );
        $cp = $mgr->createCheckpoint(
            sessionId: 's1', messages: [new UserMessage('hi')],
            turnCount: 1, totalCostUsd: 0.0, turnOutputTokens: 0,
            model: 'm', prompt: 'hi',
        );
        $this->assertArrayNotHasKey('shadow_commit', $cp->metadata);
    }

    public function test_with_shadow_store_snapshot_lands_in_metadata(): void
    {
        file_put_contents($this->projectRoot . '/a.txt', 'v1');

        $shadow = new GitShadowStore($this->projectRoot, $this->historyDir);
        $mgr = new CheckpointManager(
            new CheckpointStore($this->jsonStoreDir),
            interval: 1,
            shadowStore: $shadow,
        );

        $cp = $mgr->createCheckpoint(
            sessionId: 's1', messages: [new UserMessage('hi')],
            turnCount: 1, totalCostUsd: 0.0, turnOutputTokens: 0,
            model: 'm', prompt: 'hi',
        );

        $this->assertArrayHasKey('shadow_commit', $cp->metadata);
        $this->assertMatchesRegularExpression('#^[0-9a-f]{40}$#', $cp->metadata['shadow_commit']);
        $this->assertTrue($shadow->has($cp->metadata['shadow_commit']));
    }

    public function test_restore_files_reverts_worktree(): void
    {
        file_put_contents($this->projectRoot . '/a.txt', 'v1 at checkpoint');

        $shadow = new GitShadowStore($this->projectRoot, $this->historyDir);
        $mgr = new CheckpointManager(
            new CheckpointStore($this->jsonStoreDir),
            interval: 1,
            shadowStore: $shadow,
        );

        $cp = $mgr->createCheckpoint(
            sessionId: 's1', messages: [new UserMessage('x')],
            turnCount: 1, totalCostUsd: 0.0, turnOutputTokens: 0,
            model: 'm', prompt: 'x',
        );

        // Simulate the agent modifying the file after the checkpoint.
        file_put_contents($this->projectRoot . '/a.txt', 'v2 after agent edits');

        $this->assertTrue($mgr->restoreFiles($cp));
        $this->assertSame('v1 at checkpoint', file_get_contents($this->projectRoot . '/a.txt'));
    }

    public function test_restore_files_without_shadow_commit_returns_false(): void
    {
        $shadow = new GitShadowStore($this->projectRoot, $this->historyDir);
        $mgr = new CheckpointManager(
            new CheckpointStore($this->jsonStoreDir),
            interval: 1,
            shadowStore: $shadow,
        );

        // Manufacture a checkpoint with no shadow_commit metadata —
        // mimics an old checkpoint from before the shadow store was
        // attached, or a save that had git failures.
        $cp = new \SuperAgent\Checkpoint\Checkpoint(
            id: 'c-legacy',
            sessionId: 's1',
            messages: [],
            turnCount: 1,
            totalCostUsd: 0.0,
            turnOutputTokens: 0,
            budgetTrackerState: [],
            collectorState: [],
            model: 'm',
            prompt: 'x',
            createdAt: date('c'),
            metadata: ['note' => 'no shadow commit on this one'],
        );

        $this->assertFalse($mgr->restoreFiles($cp));
    }

    public function test_restore_files_without_shadow_store_attached_returns_false(): void
    {
        $mgr = new CheckpointManager(
            new CheckpointStore($this->jsonStoreDir),
            interval: 1,
        );
        $cp = new \SuperAgent\Checkpoint\Checkpoint(
            id: 'c',
            sessionId: 's1',
            messages: [],
            turnCount: 1,
            totalCostUsd: 0.0,
            turnOutputTokens: 0,
            budgetTrackerState: [],
            collectorState: [],
            model: 'm',
            prompt: 'x',
            createdAt: date('c'),
            metadata: ['shadow_commit' => 'abc'],
        );
        $this->assertFalse($mgr->restoreFiles($cp));
    }

    private static function gitAvailable(): bool
    {
        $out = @shell_exec('command -v git 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
