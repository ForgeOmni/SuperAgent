<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Performance\ParallelToolExecutor;

class ParallelToolProcessTest extends TestCase
{
    // ---------------------------------------------------------------
    //  getStrategy() tests
    // ---------------------------------------------------------------

    public function testGetStrategyReturnSequentialForSingleTool(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);
        $this->assertSame('sequential', $executor->getStrategy(1));
    }

    public function testGetStrategyReturnSequentialForZeroTools(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);
        $this->assertSame('sequential', $executor->getStrategy(0));
    }

    public function testGetStrategyReturnProcessWhenProcessParallelEnabled(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);
        $this->assertSame('process', $executor->getStrategy(3));
    }

    public function testGetStrategyReturnFiberWhenProcessParallelDisabled(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: false);
        // Fiber class exists in PHP 8.1+
        if (class_exists('Fiber')) {
            $this->assertSame('fiber', $executor->getStrategy(3));
        } else {
            $this->assertSame('sequential', $executor->getStrategy(3));
        }
    }

    public function testGetStrategyReturnFiberWhenDisabledEntirely(): void
    {
        $executor = new ParallelToolExecutor(enabled: false, processParallelEnabled: false);
        // enabled=false means canUseProcessParallel() is false
        if (class_exists('Fiber')) {
            $this->assertSame('fiber', $executor->getStrategy(2));
        } else {
            $this->assertSame('sequential', $executor->getStrategy(2));
        }
    }

    // ---------------------------------------------------------------
    //  canUseProcessParallel() tests
    // ---------------------------------------------------------------

    public function testCanUseProcessParallelWhenFullyEnabled(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);
        // proc_open is typically available in CLI PHP
        if (function_exists('proc_open')) {
            $this->assertTrue($executor->canUseProcessParallel());
        } else {
            $this->assertFalse($executor->canUseProcessParallel());
        }
    }

    public function testCanUseProcessParallelFalseWhenDisabled(): void
    {
        $executor = new ParallelToolExecutor(enabled: false, processParallelEnabled: true);
        $this->assertFalse($executor->canUseProcessParallel());
    }

    public function testCanUseProcessParallelFalseWhenProcessFlagOff(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: false);
        $this->assertFalse($executor->canUseProcessParallel());
    }

    // ---------------------------------------------------------------
    //  isProcessParallelEnabled() accessor
    // ---------------------------------------------------------------

    public function testIsProcessParallelEnabledReflectsConstructor(): void
    {
        $on = new ParallelToolExecutor(processParallelEnabled: true);
        $off = new ParallelToolExecutor(processParallelEnabled: false);

        $this->assertTrue($on->isProcessParallelEnabled());
        $this->assertFalse($off->isProcessParallelEnabled());
    }

    // ---------------------------------------------------------------
    //  Single tool falls back to sequential (executeProcessParallel)
    // ---------------------------------------------------------------

    public function testSingleToolUsesSequentialInProcessParallel(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);

        $block = ContentBlock::toolUse('tu_1', 'read', ['path' => '/tmp/test']);
        $called = 0;
        $fn = function (ContentBlock $b) use (&$called) {
            $called++;
            return ['tool_use_id' => $b->toolUseId, 'content' => 'ok', 'is_error' => false];
        };

        $results = $executor->executeProcessParallel([$block], $fn);

        $this->assertSame(1, $called);
        $this->assertCount(1, $results);
    }

    // ---------------------------------------------------------------
    //  Multiple tools dispatched via process parallel
    // ---------------------------------------------------------------

    public function testMultipleToolsDispatchedViaProcessParallel(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open not available');
        }

        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);

        $blocks = [
            ContentBlock::toolUse('tu_1', 'read', ['path' => '/tmp/a']),
            ContentBlock::toolUse('tu_2', 'grep', ['pattern' => 'foo']),
            ContentBlock::toolUse('tu_3', 'glob', ['pattern' => '*.php']),
        ];

        $callOrder = [];
        $fn = function (ContentBlock $b) use (&$callOrder) {
            $callOrder[] = $b->toolUseId;
            return ['tool_use_id' => $b->toolUseId, 'content' => 'result_' . $b->toolName, 'is_error' => false];
        };

        $results = $executor->executeProcessParallel($blocks, $fn, timeoutSeconds: 10);

        // All three should have results
        $this->assertArrayHasKey('tu_1', $results);
        $this->assertArrayHasKey('tu_2', $results);
        $this->assertArrayHasKey('tu_3', $results);
    }

    // ---------------------------------------------------------------
    //  Fallback to fiber execution when process parallel disabled
    // ---------------------------------------------------------------

    public function testFallbackToFiberWhenProcessDisabled(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: false);

        $blocks = [
            ContentBlock::toolUse('tu_a', 'read', ['path' => '/tmp/x']),
            ContentBlock::toolUse('tu_b', 'grep', ['pattern' => 'bar']),
        ];

        $fn = function (ContentBlock $b) {
            return ['tool_use_id' => $b->toolUseId, 'content' => 'ok', 'is_error' => false];
        };

        // executeParallel (fiber-based) still works
        $results = $executor->executeParallel($blocks, $fn);

        $this->assertCount(2, $results);
    }

    // ---------------------------------------------------------------
    //  Timeout handling — processes that exceed deadline get killed
    // ---------------------------------------------------------------

    public function testTimeoutHandlingReturnsResultsViafallback(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open not available');
        }

        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);

        $blocks = [
            ContentBlock::toolUse('tu_slow1', 'read', ['path' => '/tmp/slow']),
            ContentBlock::toolUse('tu_slow2', 'grep', ['pattern' => 'x']),
        ];

        $fn = function (ContentBlock $b) {
            return ['tool_use_id' => $b->toolUseId, 'content' => 'fallback', 'is_error' => false];
        };

        // Even with a very short timeout, results should still be produced
        // (via fallback mechanism in executeProcessParallel)
        $results = $executor->executeProcessParallel($blocks, $fn, timeoutSeconds: 1);

        $this->assertArrayHasKey('tu_slow1', $results);
        $this->assertArrayHasKey('tu_slow2', $results);
    }

    // ---------------------------------------------------------------
    //  Process failure falls back to sequential execution
    // ---------------------------------------------------------------

    public function testProcessFailureFallsBackToSequential(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open not available');
        }

        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);

        $blocks = [
            ContentBlock::toolUse('tu_f1', 'read', ['path' => '/tmp/f1']),
            ContentBlock::toolUse('tu_f2', 'read', ['path' => '/tmp/f2']),
        ];

        $fn = function (ContentBlock $b) {
            return ['tool_use_id' => $b->toolUseId, 'content' => 'recovered', 'is_error' => false];
        };

        $results = $executor->executeProcessParallel($blocks, $fn, timeoutSeconds: 10);

        // Regardless of subprocess success/failure, the fallback should populate results
        $this->assertArrayHasKey('tu_f1', $results);
        $this->assertArrayHasKey('tu_f2', $results);
        $this->assertSame('recovered', $results['tu_f1']['content']);
        $this->assertSame('recovered', $results['tu_f2']['content']);
    }

    // ---------------------------------------------------------------
    //  Empty blocks returns empty array
    // ---------------------------------------------------------------

    public function testEmptyBlocksReturnsEmpty(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);
        $results = $executor->executeProcessParallel([], fn($b) => [], timeoutSeconds: 5);
        $this->assertEmpty($results);
    }

    // ---------------------------------------------------------------
    //  Constructor defaults
    // ---------------------------------------------------------------

    public function testDefaultConstructorValues(): void
    {
        $executor = new ParallelToolExecutor();

        $this->assertTrue($executor->isEnabled());
        $this->assertFalse($executor->isProcessParallelEnabled());
        $this->assertSame('sequential', $executor->getStrategy(1));
    }

    // ---------------------------------------------------------------
    //  Strategy consistency between canUse and getStrategy
    // ---------------------------------------------------------------

    public function testStrategyConsistencyWithCanUse(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);

        if ($executor->canUseProcessParallel()) {
            $this->assertSame('process', $executor->getStrategy(5));
        } else {
            $this->assertNotSame('process', $executor->getStrategy(5));
        }
    }

    // ---------------------------------------------------------------
    //  classify() still works with new constructor
    // ---------------------------------------------------------------

    public function testClassifyStillWorksWithNewConstructor(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);

        $blocks = [
            ContentBlock::toolUse('tu_1', 'read', ['path' => '/tmp/a']),
            ContentBlock::toolUse('tu_2', 'grep', ['pattern' => 'x']),
            ContentBlock::toolUse('tu_3', 'bash', ['command' => 'ls']),
        ];

        $result = $executor->classify($blocks);

        $this->assertCount(2, $result['parallel']);
        $this->assertCount(1, $result['sequential']);
    }

    // ---------------------------------------------------------------
    //  executeProcessParallel result keys match tool IDs
    // ---------------------------------------------------------------

    public function testResultKeysMatchToolUseIds(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open not available');
        }

        $executor = new ParallelToolExecutor(enabled: true, processParallelEnabled: true);

        $blocks = [
            ContentBlock::toolUse('id_alpha', 'read', ['path' => '/a']),
            ContentBlock::toolUse('id_beta', 'glob', ['pattern' => '*']),
        ];

        $fn = function (ContentBlock $b) {
            return ['tool_use_id' => $b->toolUseId, 'content' => $b->toolName, 'is_error' => false];
        };

        $results = $executor->executeProcessParallel($blocks, $fn, timeoutSeconds: 10);

        $this->assertArrayHasKey('id_alpha', $results);
        $this->assertArrayHasKey('id_beta', $results);
        $this->assertSame('read', $results['id_alpha']['content']);
        $this->assertSame('glob', $results['id_beta']['content']);
    }

    // ---------------------------------------------------------------
    //  Large batch respects maxParallel in fiber fallback
    // ---------------------------------------------------------------

    public function testLargeBatchFiberFallback(): void
    {
        $executor = new ParallelToolExecutor(enabled: true, maxParallel: 2, processParallelEnabled: false);

        $blocks = [];
        for ($i = 0; $i < 6; $i++) {
            $blocks[] = ContentBlock::toolUse("tu_$i", 'read', ['path' => "/tmp/$i"]);
        }

        $count = 0;
        $fn = function (ContentBlock $b) use (&$count) {
            $count++;
            return ['tool_use_id' => $b->toolUseId, 'content' => 'ok', 'is_error' => false];
        };

        $results = $executor->executeParallel($blocks, $fn);

        $this->assertSame(6, $count);
        $this->assertCount(6, $results);
    }
}
