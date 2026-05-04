<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use SuperAgent\Memory\VectorIndex\BruteForceVectorIndex;
use SuperAgent\Memory\VectorIndex\IndexedItem;
use SuperAgent\Memory\VectorIndex\VectorIndexFactory;

final class BruteForceVectorIndexTest extends TestCase
{
    public function test_count_and_dimensions(): void
    {
        $idx = new BruteForceVectorIndex(3);
        $this->assertSame(0, $idx->count());
        $this->assertSame(3, $idx->dimensions());
    }

    public function test_add_and_search_returns_top_k_in_score_order(): void
    {
        $idx = new BruteForceVectorIndex(3);
        $idx->add('a', [1.0, 0.0, 0.0]);
        $idx->add('b', [0.9, 0.1, 0.0]);
        $idx->add('c', [0.0, 1.0, 0.0]);
        $idx->add('d', [0.0, 0.0, 1.0]);

        // Query along x — a and b should rank top.
        $hits = $idx->search([1.0, 0.0, 0.0], k: 2);
        $this->assertCount(2, $hits);
        $this->assertSame('a', $hits[0]->id);
        $this->assertSame('b', $hits[1]->id);
        $this->assertEqualsWithDelta(1.0, $hits[0]->score, 1e-9);
        // b normalised then dotted with x-unit ≈ 0.9 / sqrt(0.82)
        $this->assertGreaterThan(0.9, $hits[1]->score);
    }

    public function test_search_respects_min_score_filter(): void
    {
        $idx = new BruteForceVectorIndex(2);
        $idx->add('north', [0.0, 1.0]);
        $idx->add('south', [0.0, -1.0]);

        $hits = $idx->search([0.0, 1.0], k: 5, minScore: 0.5);
        $this->assertCount(1, $hits, 'south should be filtered (cos = -1)');
        $this->assertSame('north', $hits[0]->id);
    }

    public function test_zero_vector_yields_zero_score(): void
    {
        $idx = new BruteForceVectorIndex(3);
        $idx->add('zero', [0.0, 0.0, 0.0]);
        $idx->add('any', [1.0, 0.0, 0.0]);
        $hits = $idx->search([1.0, 0.0, 0.0], k: 5);
        // zero vector should not beat anything; any should be top.
        $this->assertSame('any', $hits[0]->id);
    }

    public function test_remove_evicts_item(): void
    {
        $idx = new BruteForceVectorIndex(2);
        $idx->add('a', [1.0, 0.0]);
        $idx->add('b', [0.0, 1.0]);
        $this->assertSame(2, $idx->count());
        $idx->remove('a');
        $this->assertSame(1, $idx->count());
        $hits = $idx->search([1.0, 0.0], k: 5);
        $this->assertCount(1, $hits);
        $this->assertSame('b', $hits[0]->id);
    }

    public function test_clear_resets_count(): void
    {
        $idx = new BruteForceVectorIndex(2);
        $idx->add('a', [1.0, 0.0]);
        $idx->add('b', [0.0, 1.0]);
        $idx->clear();
        $this->assertSame(0, $idx->count());
        $this->assertSame([], $idx->search([1.0, 0.0], k: 5));
    }

    public function test_dimension_mismatch_throws_on_add(): void
    {
        $idx = new BruteForceVectorIndex(3);
        $this->expectException(\InvalidArgumentException::class);
        $idx->add('bad', [1.0, 0.0]);
    }

    public function test_dimension_mismatch_throws_on_search(): void
    {
        $idx = new BruteForceVectorIndex(3);
        $idx->add('a', [1.0, 0.0, 0.0]);
        $this->expectException(\InvalidArgumentException::class);
        $idx->search([1.0, 0.0], k: 1);
    }

    public function test_addAll_handles_iterable(): void
    {
        $idx = new BruteForceVectorIndex(2);
        $idx->addAll([
            new IndexedItem('a', [1.0, 0.0], ['kind' => 'project']),
            new IndexedItem('b', [0.0, 1.0], ['kind' => 'user']),
        ]);
        $this->assertSame(2, $idx->count());

        $hits = $idx->search([1.0, 0.0], k: 1);
        $this->assertSame('a', $hits[0]->id);
        $this->assertSame('project', $hits[0]->payload['kind']);
    }

    public function test_replace_by_id_is_idempotent_in_count(): void
    {
        $idx = new BruteForceVectorIndex(2);
        $idx->add('x', [1.0, 0.0]);
        $idx->add('x', [0.0, 1.0]);
        $this->assertSame(1, $idx->count());

        $hits = $idx->search([0.0, 1.0], k: 1);
        $this->assertSame('x', $hits[0]->id);
        $this->assertEqualsWithDelta(1.0, $hits[0]->score, 1e-9);
    }

    public function test_factory_picks_brute_when_no_node_script(): void
    {
        $idx = VectorIndexFactory::create(dimensions: 4, serverScript: null);
        $this->assertInstanceOf(BruteForceVectorIndex::class, $idx);
        $this->assertSame(4, $idx->dimensions());
    }

    public function test_factory_env_override_forces_brute(): void
    {
        $prev = getenv('SUPERAGENT_VECTOR_BACKEND');
        putenv('SUPERAGENT_VECTOR_BACKEND=brute');
        try {
            // Even with a "real" script, the env override forces brute
            // — useful for tests where Node may be installed but we want
            // deterministic behavior.
            $tmp = tempnam(sys_get_temp_dir(), 'fakebridge') . '.js';
            file_put_contents($tmp, '');
            $idx = VectorIndexFactory::create(dimensions: 4, serverScript: $tmp);
            $this->assertInstanceOf(BruteForceVectorIndex::class, $idx);
            @unlink($tmp);
        } finally {
            if ($prev === false) putenv('SUPERAGENT_VECTOR_BACKEND');
            else putenv("SUPERAGENT_VECTOR_BACKEND={$prev}");
        }
    }

    public function test_realistic_dim_384_search(): void
    {
        // Simulate the typical MiniLM-L6-v2 dim. Insert 50 random
        // vectors + one "target" we can find; verify the target
        // ranks #1 against itself.
        $dim = 384;
        $idx = new BruteForceVectorIndex($dim);

        for ($i = 0; $i < 50; $i++) {
            $v = [];
            for ($j = 0; $j < $dim; $j++) $v[] = mt_rand(-100, 100) / 100.0;
            $idx->add("rand-{$i}", $v);
        }
        $target = [];
        for ($j = 0; $j < $dim; $j++) $target[] = (($j % 7) - 3) / 5.0;
        $idx->add('target', $target);

        $hits = $idx->search($target, k: 1);
        $this->assertSame('target', $hits[0]->id);
        $this->assertEqualsWithDelta(1.0, $hits[0]->score, 1e-9);
    }
}
