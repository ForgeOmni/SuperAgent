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

    public function test_deepseek_v4_flash_pricing(): void
    {
        // V4 Flash: $0.14/M input + $0.55/M output per the catalog.
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('deepseek-v4-flash', $usage);
        $this->assertEqualsWithDelta(0.14 + 0.55, $cost, 0.001);
    }

    public function test_deepseek_v4_pro_pricing(): void
    {
        $usage = new Usage(1_000_000, 1_000_000);
        $cost = CostCalculator::calculate('deepseek-v4-pro', $usage);
        $this->assertEqualsWithDelta(0.55 + 2.20, $cost, 0.001);
    }

    public function test_cache_read_billed_at_one_tenth_input_price(): void
    {
        // 800 cache hits + 200 uncached input + 50 output, V4 Flash @ $0.14/M in.
        // Expected:
        //   uncached input: 200 * 0.14/1M  = 0.000028
        //   cached read   : 800 * 0.014/1M = 0.0000112
        //   output        : 50  * 0.55/1M  = 0.0000275
        // Total ≈ 0.0000667
        $usage = new Usage(
            inputTokens: 200,
            outputTokens: 50,
            cacheReadInputTokens: 800,
        );
        $cost = CostCalculator::calculate('deepseek-v4-flash', $usage);
        $this->assertEqualsWithDelta(0.0000667, $cost, 0.000001);
    }
}
