<?php

namespace SuperAgent\Tests\Session;

use PHPUnit\Framework\TestCase;
use SuperAgent\Session\SessionManager;

class SessionManagerForkTest extends TestCase
{
    private string $tmpDir;
    private SessionManager $mgr;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sa_branch_' . uniqid();
        mkdir($this->tmpDir . '/sessions', 0775, true);
        $this->mgr = new SessionManager($this->tmpDir . '/sessions');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function test_fork_creates_new_session_with_prefix_only(): void
    {
        $cwd = $this->tmpDir;
        $msgs = [
            ['role' => 'user', 'content' => 'msg-0'],
            ['role' => 'assistant', 'content' => 'msg-1'],
            ['role' => 'user', 'content' => 'msg-2'],
            ['role' => 'assistant', 'content' => 'msg-3'],
        ];
        $this->mgr->save('s-1', $msgs, ['cwd' => $cwd, 'model' => 'opus-4-7']);

        $captured = null;
        $newId = $this->mgr->fork('s-1', 2, function ($abandoned) use (&$captured) {
            $captured = $abandoned;
            return 'Tried path X — dead end after 2 turns.';
        }, displayName: 'branch-X');

        $this->assertStringContainsString('-b-', $newId);

        $forked = $this->mgr->loadById($newId);
        $this->assertNotNull($forked);
        $this->assertCount(2, $forked['messages']);
        $this->assertSame('msg-0', $forked['messages'][0]['content']);
        $this->assertSame('s-1', $forked['branched_from']['session_id']);
        $this->assertSame(2, $forked['branched_from']['fork_at_index']);

        // Abandoned-branch summary was captured + stored on source
        $this->assertCount(2, $captured);
        $branches = $this->mgr->getBranches('s-1');
        $this->assertCount(1, $branches);
        $this->assertSame($newId, $branches[0]['branch_session_id']);
        $this->assertSame('Tried path X — dead end after 2 turns.', $branches[0]['abandoned_summary']);
    }

    public function test_fork_entries_get_ids_and_parent_chain(): void
    {
        $cwd = $this->tmpDir;
        $this->mgr->save('s-2', [
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
            ['role' => 'user', 'content' => 'c'],
        ], ['cwd' => $cwd]);

        $newId = $this->mgr->fork('s-2', 3, null);
        $forked = $this->mgr->loadById($newId);

        $entries = $forked['messages'];
        $this->assertCount(3, $entries);
        $this->assertNotEmpty($entries[0]['_entry_id']);
        $this->assertNull($entries[0]['_parent_entry_id']);
        $this->assertSame($entries[0]['_entry_id'], $entries[1]['_parent_entry_id']);
        $this->assertSame($entries[1]['_entry_id'], $entries[2]['_parent_entry_id']);
    }

    public function test_fork_with_no_summary_fn_skips_abandoned_summary(): void
    {
        $cwd = $this->tmpDir;
        $this->mgr->save('s-3', [
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'assistant', 'content' => 'b'],
        ], ['cwd' => $cwd]);

        $newId = $this->mgr->fork('s-3', 1, null);
        $branches = $this->mgr->getBranches('s-3');
        $this->assertNull($branches[0]['abandoned_summary']);
    }

    public function test_fork_throws_on_missing_source(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mgr->fork('does-not-exist', 0, null);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
