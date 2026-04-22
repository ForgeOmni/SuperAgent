<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Compat;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelCatalog;

/**
 * Lockdown test for schema v1 `models.json` parsing.
 *
 * Purpose: if any future change silently breaks how v1 catalogs are parsed,
 * THIS test must turn red. The assertions pin the exact field-to-value
 * mapping that every external consumer of ModelCatalog relies on.
 *
 * This test MUST NOT be loosened. If you need to change a mapping, bump the
 * loader to handle v3 and leave v1/v2 semantics untouched.
 */
class V1CatalogLockdownTest extends TestCase
{
    /**
     * Representative v1 catalog that exercises every v1 field.
     */
    private const V1_JSON = <<<'JSON'
    {
      "_meta": {
        "schema_version": 1,
        "updated": "2025-01-01",
        "note": "legacy v1 fixture"
      },
      "providers": {
        "legacy-provider": {
          "env": "LEGACY_API_KEY",
          "models": [
            {
              "id":          "legacy-flagship",
              "family":      "flagship",
              "date":        "20250101",
              "input":       1.25,
              "output":      10.0,
              "aliases":     ["flag", "legacy"],
              "description": "Legacy flagship"
            },
            {
              "id":     "legacy-mini",
              "family": "mini",
              "date":   "20240101",
              "input":  0.10,
              "output": 0.50
            }
          ]
        }
      }
    }
    JSON;

    private string $tmp;

    protected function setUp(): void
    {
        ModelCatalog::clearOverrides();
        $this->tmp = tempnam(sys_get_temp_dir(), 'compat_v1_') . '.json';
        file_put_contents($this->tmp, self::V1_JSON);
        ModelCatalog::loadFromFile($this->tmp);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        ModelCatalog::clearOverrides();
    }

    public function test_v1_fields_map_to_expected_shape(): void
    {
        $m = ModelCatalog::model('legacy-flagship');
        $this->assertIsArray($m);
        $this->assertSame('legacy-flagship', $m['id']);
        $this->assertSame('legacy-provider', $m['provider']);
        $this->assertSame('flagship', $m['family']);
        $this->assertSame(20250101, $m['date']);               // coerced to int
        $this->assertSame(1.25, $m['input']);
        $this->assertSame(10.0, $m['output']);
        $this->assertSame(['flag', 'legacy'], $m['aliases']);
        $this->assertSame('Legacy flagship', $m['description']);
    }

    public function test_v1_pricing_lookup_unchanged(): void
    {
        $p = ModelCatalog::pricing('legacy-flagship');
        $this->assertSame(['input' => 1.25, 'output' => 10.0], $p);
    }

    public function test_v1_alias_resolution_unchanged(): void
    {
        $this->assertSame('legacy-flagship', ModelCatalog::resolveAlias('flag'));
        $this->assertSame('legacy-flagship', ModelCatalog::resolveAlias('FLAG'));
        $this->assertSame('legacy-flagship', ModelCatalog::resolveAlias('flagship'));
    }

    public function test_v1_models_for_provider_returns_all_entries(): void
    {
        $rows = ModelCatalog::modelsFor('legacy-provider');
        $ids = array_column($rows, 'id');
        $this->assertContains('legacy-flagship', $ids);
        $this->assertContains('legacy-mini', $ids);
    }

    public function test_v1_entry_has_no_capabilities_field(): void
    {
        // A v1 entry never carries `capabilities`; capabilitiesFor() must then
        // fall back to the provider map (see CapabilitiesDerivationTest).
        $m = ModelCatalog::model('legacy-flagship');
        $this->assertArrayNotHasKey('capabilities', $m);
        $this->assertArrayNotHasKey('regions', $m);
    }

    public function test_bundled_v1_catalog_still_loads(): void
    {
        // Reset to bundled (the shipped resources/models.json, currently v1).
        ModelCatalog::clearOverrides();
        ModelCatalog::invalidate();

        $providers = ModelCatalog::providers();
        $this->assertContains('anthropic', $providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('gemini', $providers);

        $opus = ModelCatalog::model('claude-opus-4-7');
        $this->assertNotNull($opus);
        $this->assertSame(15.0, $opus['input']);
        $this->assertSame(75.0, $opus['output']);
    }
}
