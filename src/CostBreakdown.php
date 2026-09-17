<?php

declare(strict_types=1);

namespace SuperAgent;

/**
 * A cost, and where its numbers came from.
 *
 * `CostCalculator::calculate()` always returns a number: an unknown model
 * falls back to Sonnet pricing, and the caller cannot tell that apart from a
 * real quote. That is fine for a progress line and wrong for anything that
 * writes money, where a corrected price and a billing bug have to be
 * distinguishable months later.
 *
 * @since 1.2.0
 */
final class CostBreakdown
{
    /**
     * @param string $source  How the price was found:
     *                        `catalog` — the JSON price list,
     *                        `table` — the built-in table,
     *                        `prefix` — a longest-prefix match on a known id,
     *                        `family` — a per-vendor default ("something claude"),
     *                        `fallback` — nothing matched; Sonnet pricing assumed.
     */
    public function __construct(
        public readonly float $cost,
        public readonly string $model,
        public readonly float $inputPerMillion,
        public readonly float $outputPerMillion,
        public readonly string $source,
        public readonly string $catalogVersion,
    ) {
    }

    /** True when the price was guessed rather than looked up. */
    public function isEstimate(): bool
    {
        return $this->source === 'family' || $this->source === 'fallback';
    }

    /** @return array<string,mixed> shape for a usage ledger row */
    public function toArray(): array
    {
        return [
            'cost_usd' => $this->cost,
            'model' => $this->model,
            'input_per_million' => $this->inputPerMillion,
            'output_per_million' => $this->outputPerMillion,
            'price_source' => $this->source,
            'catalog_version' => $this->catalogVersion,
            'is_estimate' => $this->isEstimate(),
        ];
    }
}
