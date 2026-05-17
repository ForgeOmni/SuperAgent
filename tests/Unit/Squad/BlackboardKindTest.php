<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\Blackboard;

/**
 * Typed blackboard contract: kind defaults to 'note', convenience
 * methods stamp known kinds, entriesBy / entriesByKind filter
 * correctly, fromEntries is forward-compatible with pre-1.1 entries
 * that lacked the kind field.
 */
final class BlackboardKindTest extends TestCase
{
    public function test_default_kind_is_note(): void
    {
        $b = new Blackboard();
        $b->write('researcher', 'finding', 'X is the case');
        $entries = $b->entries();
        $this->assertSame(Blackboard::KIND_NOTE, $entries[0]['kind']);
    }

    public function test_convenience_methods_stamp_kind(): void
    {
        $b = new Blackboard();
        $b->claim('researcher', 'c1', 'we should use Redis');
        $b->evidence('researcher', 'e1', '99th-pct cache miss is 80ms');
        $b->risk('verifier', 'r1', 'cache TTL drift');
        $b->decision('lead', 'd1', 'approved');

        $byKind = $b->entriesByKind();
        $this->assertCount(1, $byKind[Blackboard::KIND_CLAIM]);
        $this->assertCount(1, $byKind[Blackboard::KIND_EVIDENCE]);
        $this->assertCount(1, $byKind[Blackboard::KIND_RISK]);
        $this->assertCount(1, $byKind[Blackboard::KIND_DECISION]);
    }

    public function test_entries_by_filters_by_kind(): void
    {
        $b = new Blackboard();
        $b->claim('a', 'c1', 1);
        $b->claim('b', 'c2', 2);
        $b->risk('c', 'r1', 3);
        $claims = $b->entriesBy(Blackboard::KIND_CLAIM);
        $this->assertCount(2, $claims);
        $risks = $b->entriesBy(Blackboard::KIND_RISK);
        $this->assertCount(1, $risks);
    }

    public function test_from_entries_backfills_kind_when_missing(): void
    {
        // Pre-1.1 entries didn't carry a `kind` field.
        $entries = [
            ['role' => 'a', 'key' => 'x', 'value' => 1],
            ['role' => 'b', 'key' => 'y', 'value' => 2, 'kind' => Blackboard::KIND_RISK],
        ];
        $b = Blackboard::fromEntries($entries);
        $rehydrated = $b->entries();
        $this->assertSame(Blackboard::KIND_NOTE, $rehydrated[0]['kind']);
        $this->assertSame(Blackboard::KIND_RISK, $rehydrated[1]['kind']);
    }
}
