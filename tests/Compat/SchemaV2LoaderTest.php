<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Compat;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelCatalog;

/**
 * Verifies the schema v2 additive fields — `capabilities` and `regions` —
 * are preserved by the loader at both provider and model scope, and that
 * v2 catalogs remain interchangeable with v1 callers (no new required fields).
 */
class SchemaV2LoaderTest extends TestCase
{
    private const V2_JSON = <<<'JSON'
    {
      "_meta": {"schema_version": 2},
      "providers": {
        "kimi": {
          "regions": ["intl", "cn"],
          "capabilities": {
            "streaming": true,
            "tools": true,
            "mcp": true
          },
          "models": [
            {
              "id": "kimi-k2-6",
              "family": "k2",
              "input": 0.60,
              "output": 2.50,
              "capabilities": {
                "streaming": true,
                "tools": true,
                "thinking": true,
                "swarm": true,
                "skills": true,
                "file_extract": true,
                "mcp": true,
                "max_context": 262144
              }
            },
            {
              "id": "kimi-legacy",
              "input": 0.20,
              "output": 0.80
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
        $this->tmp = tempnam(sys_get_temp_dir(), 'compat_v2_') . '.json';
        file_put_contents($this->tmp, self::V2_JSON);
        ModelCatalog::loadFromFile($this->tmp);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        ModelCatalog::clearOverrides();
    }

    public function test_model_level_capabilities_preserved(): void
    {
        $caps = ModelCatalog::capabilitiesFor('kimi-k2-6');
        $this->assertTrue($caps['thinking']);
        $this->assertTrue($caps['swarm']);
        $this->assertTrue($caps['skills']);
        $this->assertSame(262144, $caps['max_context']);
    }

    public function test_model_inherits_provider_level_capabilities_when_not_set(): void
    {
        // kimi-legacy has no `capabilities` of its own → inherits the provider
        // block's `capabilities` (streaming/tools/mcp).
        $caps = ModelCatalog::capabilitiesFor('kimi-legacy');
        $this->assertTrue($caps['streaming']);
        $this->assertTrue($caps['tools']);
        $this->assertTrue($caps['mcp']);
        $this->assertArrayNotHasKey('thinking', $caps);
    }

    public function test_model_inherits_provider_level_regions(): void
    {
        $this->assertSame(['intl', 'cn'], ModelCatalog::regionsFor('kimi-k2-6'));
        $this->assertSame(['intl', 'cn'], ModelCatalog::regionsFor('kimi-legacy'));
    }

    public function test_model_level_regions_override_provider_defaults(): void
    {
        ModelCatalog::register('kimi-intl-only', [
            'provider' => 'kimi',
            'regions'  => ['intl'],  // model-level wins over provider-level ['intl','cn']
        ]);

        $this->assertSame(['intl'], ModelCatalog::regionsFor('kimi-intl-only'));
    }

    public function test_v2_catalog_v1_callers_still_see_pricing_and_family(): void
    {
        // A v1-era caller (CostCalculator, ModelResolver) must still find pricing
        // / family unchanged on a v2 entry.
        $p = ModelCatalog::pricing('kimi-k2-6');
        $this->assertSame(['input' => 0.60, 'output' => 2.50], $p);

        $m = ModelCatalog::model('kimi-k2-6');
        $this->assertSame('k2', $m['family']);
        $this->assertSame('kimi', $m['provider']);
    }
}
