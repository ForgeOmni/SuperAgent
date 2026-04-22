<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Providers\CapabilityRouter;
use SuperAgent\Providers\ModelCatalog;

class CapabilityRouterTest extends TestCase
{
    protected function setUp(): void
    {
        ModelCatalog::clearOverrides();
    }

    protected function tearDown(): void
    {
        ModelCatalog::clearOverrides();
    }

    public function test_pins_to_explicit_provider(): void
    {
        $decision = CapabilityRouter::pick([
            'provider' => 'kimi',
        ]);
        $this->assertSame('kimi', $decision->provider);
        // Pick whatever Kimi model is at the top of the catalog — exact id
        // not pinned here, the list may change.
        $this->assertNotEmpty($decision->model);
    }

    public function test_required_feature_filters_out_providers_without_support(): void
    {
        // `swarm` is only declared by Kimi in the bundled catalog — requiring
        // it across all providers should narrow the result to Kimi.
        $decision = CapabilityRouter::pick([
            'features' => ['swarm' => ['required' => true]],
        ]);
        $this->assertSame('kimi', $decision->provider);
    }

    public function test_required_feature_with_no_support_throws(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/required features/');

        CapabilityRouter::pick([
            'features' => ['definitely-nonexistent-feature-xyz' => ['required' => true]],
        ]);
    }

    public function test_region_preference_narrows_candidates(): void
    {
        $decision = CapabilityRouter::pick([
            'provider' => 'qwen',
            'region' => 'us',
        ]);
        $this->assertSame('qwen', $decision->provider);
        $this->assertSame('us', $decision->region);
    }

    public function test_region_preference_is_soft_when_no_candidate_matches(): void
    {
        // Kimi only has ['intl','cn'] regions — asking for 'us' should not
        // error out, router falls back to any Kimi candidate (region hint
        // is advisory when the hard pin is provider).
        $decision = CapabilityRouter::pick([
            'provider' => 'kimi',
            'region' => 'us',
        ]);
        $this->assertSame('kimi', $decision->provider);
        // Router keeps the requested region so downstream code can decide
        // how to handle the mismatch (the provider itself will throw at
        // construction time on an unknown region — see KimiProviderTest).
        $this->assertSame('us', $decision->region);
    }

    public function test_preferred_list_breaks_ties(): void
    {
        // Every provider supports `tools` — no filter kicks in. `preferred`
        // list should decide the winner.
        $decision = CapabilityRouter::pick([
            'features' => ['tools' => []],
            'preferred' => ['minimax', 'kimi', 'qwen'],
        ]);
        $this->assertSame('minimax', $decision->provider);
    }

    public function test_native_feature_count_is_tiebreaker(): void
    {
        // Ask for thinking + swarm (non-required). Only Kimi has swarm, so
        // even without `preferred`, Kimi should rank higher than others for
        // thinking+swarm combo.
        $decision = CapabilityRouter::pick([
            'features' => [
                'thinking' => [],
                'swarm' => [],
            ],
        ]);
        $this->assertSame('kimi', $decision->provider);
    }

    public function test_decision_carries_feature_spec(): void
    {
        $features = ['thinking' => ['budget' => 4000]];
        $decision = CapabilityRouter::pick([
            'provider' => 'glm',
            'features' => $features,
        ]);
        $this->assertSame($features, $decision->features);
    }

    public function test_empty_catalog_throws(): void
    {
        // Load a fixture catalog with no models to force the empty path.
        $tmp = tempnam(sys_get_temp_dir(), 'empty_catalog_') . '.json';
        file_put_contents($tmp, '{"providers": {}}');
        ModelCatalog::loadFromFile($tmp);
        try {
            $this->expectException(ProviderException::class);
            CapabilityRouter::pick([]);
        } finally {
            @unlink($tmp);
        }
    }
}
