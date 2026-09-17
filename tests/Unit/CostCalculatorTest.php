<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostCalculator;
use SuperAgent\Messages\Usage;

class CostCalculatorTest extends TestCase
{
    public function test_sonnet_pricing(): void
    {
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('claude-sonnet-4-20250514', $usage);

        // $3/M input + $15/M output = $18
        $this->assertEqualsWithDelta(18.0, $cost, 0.001);
    }

    public function test_opus_pricing(): void
    {
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('claude-opus-4-20250514', $usage);

        // $15/M input + $75/M output = $90
        $this->assertEqualsWithDelta(90.0, $cost, 0.001);
    }

    public function test_fable_pricing(): void
    {
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('claude-fable-5', $usage);

        // $10/M input + $50/M output = $60 (official Fable 5 pricing, above Opus)
        $this->assertEqualsWithDelta(60.0, $cost, 0.001);
    }

    public function test_opus_5_pricing(): void
    {
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('claude-opus-5', $usage);

        // $5/M input + $25/M output = $30 (same tier as Opus 4.8)
        $this->assertEqualsWithDelta(30.0, $cost, 0.001);
    }

    public function test_small_usage(): void
    {
        $usage = new Usage(100, 50);
        $cost = CostCalculator::calculate('claude-sonnet-4-20250514', $usage);

        // 100 * 3/1M + 50 * 15/1M = 0.0003 + 0.00075 = 0.00105
        $this->assertEqualsWithDelta(0.00105, $cost, 0.00001);
    }

    public function test_unknown_model_uses_default(): void
    {
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('some-unknown-model', $usage);

        // Falls back to sonnet pricing
        $this->assertEqualsWithDelta(18.0, $cost, 0.001);
    }

    public function test_zero_usage(): void
    {
        $usage = new Usage(0, 0);
        $cost = CostCalculator::calculate('claude-sonnet-4-20250514', $usage);

        $this->assertSame(0.0, $cost);
    }

    public function test_register_custom_model(): void
    {
        CostCalculator::register('my-custom-model', 1.0, 2.0);

        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('my-custom-model', $usage);

        $this->assertEqualsWithDelta(3.0, $cost, 0.001);
    }

    public function test_deepseek_flash_pricing(): void
    {
        // V4.1 Flash (GA 2026-09-10, served as `deepseek-flash`): $0.15/M
        // input + $0.60/M output off-peak base per api-docs.deepseek.com.
        // The retired `deepseek-v4-flash` id routes here and bills the same.
        $usage = new Usage(1_000_000, 1_000_000);
        $this->assertEqualsWithDelta(0.15 + 0.60, CostCalculator::calculate('deepseek-flash', $usage), 0.001);
        $this->assertEqualsWithDelta(0.15 + 0.60, CostCalculator::calculate('deepseek-v4-flash', $usage), 0.001);
    }

    public function test_gpt_56_and_grok_45_pricing(): void
    {
        $usage = new Usage(1_000_000, 1_000_000);
        // Repriced at the GPT-6 Astra launch (2026-09-03).
        $this->assertEqualsWithDelta(60.0, CostCalculator::calculate('gpt-6-astra', $usage), 0.001);
        $this->assertEqualsWithDelta(24.0, CostCalculator::calculate('gpt-5.6-sol', $usage), 0.001);
        $this->assertEqualsWithDelta(14.0, CostCalculator::calculate('gpt-5.6-terra', $usage), 0.001);
        $this->assertEqualsWithDelta(1.4, CostCalculator::calculate('gpt-5.6-luna', $usage), 0.001);
        $this->assertEqualsWithDelta(8.0, CostCalculator::calculate('grok-4.6', $usage), 0.001);
        $this->assertEqualsWithDelta(8.0, CostCalculator::calculate('grok-4.5', $usage), 0.001);
    }

    public function test_deepseek_v4_pro_pricing(): void
    {
        // V4 Pro GA (0813): $0.66/M input + $1.98/M output off-peak base
        // per the catalog (peak hours bill 2x server-side).
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('deepseek-v4-pro', $usage);
        $this->assertEqualsWithDelta(0.66 + 1.98, $cost, 0.001);
    }

    public function test_cache_read_billed_at_one_tenth_input_price(): void
    {
        // 800 cache hits + 200 uncached input + 50 output, V4.1 Flash @
        // $0.15/M in. Expected:
        //   uncached input: 200 * 0.15/1M  = 0.00003
        //   cached read   : 800 * 0.015/1M = 0.000012
        //   output        : 50  * 0.60/1M  = 0.00003
        // Total ≈ 0.000072
        $usage = new Usage(
            inputTokens: 200,
            outputTokens: 50,
            cacheReadInputTokens: 800,
        );
        $cost = CostCalculator::calculate('deepseek-flash', $usage);
        $this->assertEqualsWithDelta(0.000072, $cost, 0.000001);
    }
}
