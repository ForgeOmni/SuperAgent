<?php

namespace SuperAgent\Tests\Unit\CostAutopilot;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostAutopilot\ModelTier;

class ModelTierTest extends TestCase
{
    public function test_blended_cost(): void
    {
        $tier = new ModelTier('test', 'model-a', 3.0, 15.0, 20);

        $this->assertSame(9.0, $tier->blendedCostPerMillion());
    }

    public function test_is_free(): void
    {
        $free = new ModelTier('free', 'llama3', 0.0, 0.0, 0);
        $paid = new ModelTier('paid', 'claude', 3.0, 15.0, 10);

        $this->assertTrue($free->isFree());
        $this->assertFalse($paid->isFree());
    }

    public function test_anthropic_tiers(): void
    {
        $tiers = ModelTier::anthropicTiers();

        $this->assertCount(3, $tiers);
        $this->assertSame('opus', $tiers[0]->name);
        $this->assertSame('sonnet', $tiers[1]->name);
        $this->assertSame('haiku', $tiers[2]->name);

        // Priority ordering: opus > sonnet > haiku
        $this->assertGreaterThan($tiers[1]->priority, $tiers[0]->priority);
        $this->assertGreaterThan($tiers[2]->priority, $tiers[1]->priority);

        // Cost ordering: opus > sonnet > haiku
        $this->assertGreaterThan($tiers[1]->costPerMillionInput, $tiers[0]->costPerMillionInput);
        $this->assertGreaterThan($tiers[2]->costPerMillionInput, $tiers[1]->costPerMillionInput);
    }

    public function test_openai_tiers(): void
    {
        $tiers = ModelTier::openaiTiers();

        $this->assertCount(3, $tiers);
        $this->assertSame('gpt4o', $tiers[0]->name);
        $this->assertSame('gpt4o-mini', $tiers[1]->name);
        $this->assertSame('gpt35', $tiers[2]->name);
    }
}
