<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Compat;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Lockdown: `ModelCatalog::capabilitiesFor()` must fall back to
 * `ProviderRegistry::getCapabilities(<provider>)` when the model entry itself
 * does not declare `capabilities` — this is how v1 catalogs continue to be
 * usable by v2-era consumers (CapabilityRouter, FeatureAdapter) without ever
 * being migrated.
 */
class CapabilitiesDerivationTest extends TestCase
{
    protected function setUp(): void
    {
        ModelCatalog::clearOverrides();
    }

    protected function tearDown(): void
    {
        ModelCatalog::clearOverrides();
    }

    public function test_v1_entry_derives_from_provider_registry(): void
    {
        // Opus 4.7 is in bundled resources/models.json WITHOUT `capabilities`.
        // So capabilitiesFor() must delegate to the ProviderRegistry map.
        $caps = ModelCatalog::capabilitiesFor('claude-opus-4-7');
        $expected = ProviderRegistry::getCapabilities('anthropic');
        $this->assertSame($expected, $caps);
    }

    public function test_capabilities_for_unknown_model_returns_empty_array(): void
    {
        $this->assertSame([], ModelCatalog::capabilitiesFor('does-not-exist-xyz'));
    }

    public function test_capabilities_for_returns_model_level_when_present(): void
    {
        // Runtime register with capabilities → those win over derivation.
        ModelCatalog::register('my-custom-model', [
            'provider' => 'anthropic',
            'capabilities' => ['streaming' => true, 'custom_flag' => 'yes'],
        ]);

        $caps = ModelCatalog::capabilitiesFor('my-custom-model');
        $this->assertTrue($caps['streaming']);
        $this->assertSame('yes', $caps['custom_flag']);
        // Must NOT be polluted with ProviderRegistry fields like 'max_context'.
        $this->assertArrayNotHasKey('max_context', $caps);
    }

    public function test_regions_for_v1_entry_is_empty(): void
    {
        // V1 entries have no `regions`; regionsFor() must return [].
        $this->assertSame([], ModelCatalog::regionsFor('claude-opus-4-7'));
    }
}
