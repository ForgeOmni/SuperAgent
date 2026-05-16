<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\SubTask;
use SuperAgent\Squad\TaskDecomposer;

class DecomposerRefinerTest extends TestCase
{
    public function test_high_confidence_skips_refiner(): void
    {
        $prompt = "1. Research the auth system\n2. Architect a migration\n3. Implement OAuth2";
        $refinerCalls = 0;

        $d = (new TaskDecomposer())->withLlmRefiner(function () use (&$refinerCalls) {
            $refinerCalls++;
            return [];
        }, confidenceFloor: 0.5);

        $subs = $d->decomposeRefined($prompt);

        $this->assertSame(0, $refinerCalls, 'Refiner must not be called on high-confidence plans');
        $this->assertCount(3, $subs);
    }

    public function test_low_confidence_invokes_refiner(): void
    {
        $prompt = "Make my code better please.";   // No structural signal.
        $refinerCalls = 0;

        $d = (new TaskDecomposer())->withLlmRefiner(function (string $p, array $current) use (&$refinerCalls) {
            $refinerCalls++;
            return [
                new SubTask('research', 'research', 'Read the code', DifficultyClass::EASY),
                new SubTask('refactor', 'implement', 'Refactor it',  DifficultyClass::MODERATE, dependsOn: ['research']),
            ];
        }, confidenceFloor: 0.5);

        $subs = $d->decomposeRefined($prompt);

        $this->assertSame(1, $refinerCalls);
        $this->assertCount(2, $subs);
    }

    public function test_confidence_reflects_detected_signals(): void
    {
        $strong = "1. foo\n2. bar\n3. baz";
        $weak   = "Do the thing";

        $d = new TaskDecomposer();
        $this->assertGreaterThan(0.5, $d->decomposeWithConfidence($strong)->confidence);
        $this->assertLessThan(0.5, $d->decomposeWithConfidence($weak)->confidence);
    }
}
