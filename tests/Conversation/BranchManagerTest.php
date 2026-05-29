<?php

namespace SuperAgent\Tests\Conversation;

use PHPUnit\Framework\TestCase;
use SuperAgent\Conversation\BranchManager;

class BranchManagerTest extends TestCase
{
    public function test_ancestry_walks_parent_chain(): void
    {
        $bm = new BranchManager([
            ['id' => 'a', 'parentId' => null],
            ['id' => 'b', 'parentId' => 'a'],
            ['id' => 'c', 'parentId' => 'b'],
        ]);
        $this->assertSame(['a', 'b', 'c'], $bm->ancestry('c'));
    }

    public function test_leaves_excludes_internal_nodes(): void
    {
        $bm = new BranchManager([
            ['id' => 'a', 'parentId' => null],
            ['id' => 'b', 'parentId' => 'a'],
            ['id' => 'c', 'parentId' => 'b'],
            ['id' => 'd', 'parentId' => 'a'], // fork from a
        ]);
        $this->assertEqualsCanonicalizing(['c', 'd'], $bm->leaves());
    }

    public function test_common_ancestor_for_fork(): void
    {
        $bm = new BranchManager([
            ['id' => 'a', 'parentId' => null],
            ['id' => 'b', 'parentId' => 'a'],
            ['id' => 'c', 'parentId' => 'b'],
            ['id' => 'd', 'parentId' => 'b'], // fork at b
        ]);
        $this->assertSame('b', $bm->findCommonAncestor('c', 'd'));
    }

    public function test_collect_branch_returns_abandoned_path(): void
    {
        $bm = new BranchManager([
            ['id' => 'a', 'parentId' => null, 'role' => 'user'],
            ['id' => 'b', 'parentId' => 'a', 'role' => 'assistant'],
            ['id' => 'c', 'parentId' => 'b', 'role' => 'user'],
            ['id' => 'd', 'parentId' => 'b', 'role' => 'user'], // fork
        ]);
        $abandoned = $bm->collectBranch('c', 'b');
        $this->assertCount(1, $abandoned);
        $this->assertSame('c', $abandoned[0]['id']);
    }

    public function test_make_branch_summary_entry_shape(): void
    {
        $bm = new BranchManager([['id' => 'a', 'parentId' => null]]);
        $entry = $bm->makeBranchSummaryEntry('a', 'Tried approach X, found dead end.');
        $this->assertSame('branch_summary', $entry['type']);
        $this->assertSame('a', $entry['fromId']);
        $this->assertSame('Tried approach X, found dead end.', $entry['summary']);
        $this->assertNotEmpty($entry['id']);
        $this->assertNotEmpty($entry['timestamp']);
    }
}
