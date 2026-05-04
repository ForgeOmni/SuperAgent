<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use SuperAgent\Memory\VectorIndex\HnswVectorIndex;

/**
 * The Node bridge isn't expected to be available in a default test
 * environment, but the class MUST behave correctly anyway by routing
 * everything through its `BruteForceVectorIndex` fallback. These tests
 * validate that contract.
 */
final class HnswVectorIndexFallbackTest extends TestCase
{
    public function test_missing_server_script_falls_back_silently(): void
    {
        $idx = new HnswVectorIndex(
            dimensions: 4,
            serverScript: '/path/that/does/not/exist.js',
        );

        $idx->add('a', [1.0, 0.0, 0.0, 0.0]);
        $idx->add('b', [0.0, 1.0, 0.0, 0.0]);

        $this->assertSame(2, $idx->count());
        $this->assertFalse($idx->bridgeIsLive(), 'bridge should never come up');

        $hits = $idx->search([1.0, 0.0, 0.0, 0.0], k: 1);
        $this->assertCount(1, $hits);
        $this->assertSame('a', $hits[0]->id);
    }

    public function test_explicit_disable_skips_bridge_attempt(): void
    {
        $idx = new HnswVectorIndex(
            dimensions: 2,
            serverScript: '/anything', // wouldn't matter — disabled
            bridgeDisabled: true,
        );
        $idx->add('x', [1.0, 0.0]);
        $idx->add('y', [0.0, 1.0]);
        $hits = $idx->search([1.0, 0.0], k: 5);
        $this->assertCount(2, $hits);
        $this->assertSame('x', $hits[0]->id);
    }

    public function test_remove_propagates_to_fallback(): void
    {
        $idx = new HnswVectorIndex(
            dimensions: 2,
            serverScript: '/nope',
            bridgeDisabled: true,
        );
        $idx->add('a', [1.0, 0.0]);
        $idx->add('b', [0.0, 1.0]);
        $idx->remove('a');
        $this->assertSame(1, $idx->count());
    }

    public function test_clear_resets_fallback(): void
    {
        $idx = new HnswVectorIndex(
            dimensions: 2,
            serverScript: '/nope',
            bridgeDisabled: true,
        );
        $idx->add('a', [1.0, 0.0]);
        $idx->clear();
        $this->assertSame(0, $idx->count());
    }

    public function test_dimension_validation_still_active_in_fallback(): void
    {
        $idx = new HnswVectorIndex(
            dimensions: 3,
            serverScript: '/nope',
            bridgeDisabled: true,
        );
        $this->expectException(\InvalidArgumentException::class);
        $idx->add('bad', [1.0, 0.0]);
    }
}
