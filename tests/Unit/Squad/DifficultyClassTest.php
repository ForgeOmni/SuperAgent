<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;

class DifficultyClassTest extends TestCase
{
    public function test_score_lands_in_expected_band(): void
    {
        $this->assertSame(DifficultyClass::TRIVIAL, DifficultyClass::fromScore(0.10));
        $this->assertSame(DifficultyClass::EASY, DifficultyClass::fromScore(0.30));
        $this->assertSame(DifficultyClass::MODERATE, DifficultyClass::fromScore(0.50));
        $this->assertSame(DifficultyClass::HARD, DifficultyClass::fromScore(0.75));
        $this->assertSame(DifficultyClass::EXPERT, DifficultyClass::fromScore(0.95));
    }

    public function test_thresholds_align_with_smart_context_deep_thinking(): void
    {
        // SmartContext\TaskComplexity uses 0.7 as the DEEP_THINKING
        // threshold. Squad's HARD band must start at the same point so
        // a "complex enough for deep thinking" task lands in HARD/EXPERT.
        $this->assertSame(DifficultyClass::HARD, DifficultyClass::fromScore(0.70));
    }

    public function test_score_is_clamped(): void
    {
        $this->assertSame(DifficultyClass::TRIVIAL, DifficultyClass::fromScore(-5.0));
        $this->assertSame(DifficultyClass::EXPERT, DifficultyClass::fromScore(5.0));
    }

    public function test_hard_and_expert_default_to_review(): void
    {
        $this->assertTrue(DifficultyClass::HARD->defaultRequiresReview());
        $this->assertTrue(DifficultyClass::EXPERT->defaultRequiresReview());
        $this->assertFalse(DifficultyClass::MODERATE->defaultRequiresReview());
        $this->assertFalse(DifficultyClass::TRIVIAL->defaultRequiresReview());
    }
}
