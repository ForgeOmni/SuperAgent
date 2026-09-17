<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostCalculator;
use SuperAgent\Messages\Usage;
use SuperAgent\Providers\ModelCatalog;

/**
 * Where a cost came from (1.2.0).
 *
 * `calculate()` always returns a number: an unrecognised model silently gets
 * Sonnet pricing. Fine for a progress line, wrong for a ledger row, where a
 * corrected price and a billing bug have to be distinguishable later.
 */
class CostProvenanceTest extends TestCase
{
    public function test_a_catalogued_model_is_priced_not_guessed(): void
    {
        $breakdown = CostCalculator::calculateWithProvenance('gemini-3.8-flash', new Usage(1_000_000, 0));

        $this->assertFalse($breakdown->isEstimate());
        $this->assertContains($breakdown->source, ['catalog', 'table']);
        $this->assertSame($breakdown->inputPerMillion, $breakdown->cost);
    }

    public function test_an_unknown_model_says_so(): void
    {
        $breakdown = CostCalculator::calculateWithProvenance('a-model-nobody-has-heard-of', new Usage(1000, 1000));

        $this->assertTrue($breakdown->isEstimate());
        $this->assertSame('fallback', $breakdown->source);
        $this->assertGreaterThan(0.0, $breakdown->cost, 'still a number — but a declared assumption');
    }

    public function test_a_vendor_guess_is_also_an_estimate(): void
    {
        $breakdown = CostCalculator::calculateWithProvenance('claude-something-unreleased', new Usage(1000, 1000));

        $this->assertContains($breakdown->source, ['family', 'prefix']);
        $this->assertSame($breakdown->source === 'family', $breakdown->isEstimate());
    }

    public function test_the_price_list_identifies_itself(): void
    {
        $version = ModelCatalog::version();

        $this->assertMatchesRegularExpression('/^v\d+@\d{4}-\d{2}-\d{2}$/', $version);
        $this->assertSame(
            $version,
            CostCalculator::calculateWithProvenance('claude-sonnet-5', new Usage(1, 1))->catalogVersion
        );
        $this->assertArrayHasKey('schema_version', ModelCatalog::meta());
    }

    public function test_the_breakdown_is_shaped_for_a_ledger_row(): void
    {
        $row = CostCalculator::calculateWithProvenance('claude-sonnet-5', new Usage(1000, 500))->toArray();

        foreach (['cost_usd', 'model', 'input_per_million', 'output_per_million', 'price_source', 'catalog_version', 'is_estimate'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    public function test_the_cost_matches_the_plain_calculator(): void
    {
        $usage = new Usage(12_345, 6_789);

        $this->assertSame(
            CostCalculator::calculate('claude-sonnet-5', $usage),
            CostCalculator::calculateWithProvenance('claude-sonnet-5', $usage)->cost,
            'provenance must not change the number'
        );
    }
}
