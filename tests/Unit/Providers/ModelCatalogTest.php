<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelCatalog;

class ModelCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        ModelCatalog::clearOverrides();
    }

    protected function tearDown(): void
    {
        ModelCatalog::clearOverrides();
    }

    public function test_bundled_json_loads_and_has_core_providers(): void
    {
        $providers = ModelCatalog::providers();
        $this->assertContains('anthropic', $providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('gemini', $providers);
        $this->assertContains('ollama', $providers);
    }

    public function test_pricing_lookup_for_claude_opus_47(): void
    {
        $p = ModelCatalog::pricing('claude-opus-4-7');
        $this->assertNotNull($p);
        $this->assertSame(15.0, $p['input']);
        $this->assertSame(75.0, $p['output']);
    }

    public function test_pricing_lookup_for_gemini_25_flash(): void
    {
        $p = ModelCatalog::pricing('gemini-2.5-flash');
        $this->assertNotNull($p);
        $this->assertGreaterThan(0.0, $p['input']);
        $this->assertGreaterThan($p['input'], $p['output']);
    }

    public function test_models_for_provider_returns_rows(): void
    {
        $openai = ModelCatalog::modelsFor('openai');
        $this->assertNotEmpty($openai);
        $ids = array_column($openai, 'id');
        $this->assertContains('gpt-5', $ids);
        $this->assertContains('gpt-4o', $ids);
    }

    public function test_resolve_alias_picks_newest_in_family(): void
    {
        // "opus" alias should resolve to the newest Opus model (Opus 4.7 per bundled catalog)
        $this->assertSame('claude-opus-4-7', ModelCatalog::resolveAlias('opus'));
        $this->assertSame('claude-opus-4-7', ModelCatalog::resolveAlias('CLAUDE-OPUS'));
    }

    public function test_resolve_alias_returns_null_on_unknown(): void
    {
        $this->assertNull(ModelCatalog::resolveAlias('not-a-real-family'));
    }

    public function test_register_overrides_pricing(): void
    {
        ModelCatalog::register('gpt-4o', ['input' => 99.0, 'output' => 199.0, 'provider' => 'openai']);
        $p = ModelCatalog::pricing('gpt-4o');
        $this->assertSame(99.0, $p['input']);
        $this->assertSame(199.0, $p['output']);
    }

    public function test_load_from_file_replaces_catalog(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'models_') . '.json';
        file_put_contents($tmp, json_encode([
            'providers' => [
                'custom' => [
                    'models' => [
                        ['id' => 'my-custom-model', 'input' => 0.50, 'output' => 1.50, 'description' => 'Custom'],
                    ],
                ],
            ],
        ]));

        ModelCatalog::loadFromFile($tmp);
        $p = ModelCatalog::pricing('my-custom-model');
        $this->assertNotNull($p);
        $this->assertSame(0.5, $p['input']);
        $this->assertContains('custom', ModelCatalog::providers());

        @unlink($tmp);
    }

    public function test_load_from_file_rejects_invalid_json(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'models_') . '.json';
        file_put_contents($tmp, '{"not": "a catalog"}');

        $this->expectException(\RuntimeException::class);
        try {
            ModelCatalog::loadFromFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_maybe_auto_update_is_noop_without_env(): void
    {
        putenv('SUPERAGENT_MODELS_AUTO_UPDATE');
        putenv('SUPERAGENT_MODELS_URL');
        $this->assertFalse(ModelCatalog::maybeAutoUpdate());
    }

    public function test_cost_calculator_picks_up_catalog_pricing(): void
    {
        // Opus 4.7 was added via resources/models.json — CostCalculator should find it
        $usage = new \SuperAgent\Messages\Usage(1_000_000, 1_000_000);
        $cost = \SuperAgent\CostCalculator::calculate('claude-opus-4-7', $usage);
        // input 15 + output 75 = 90 USD for 1M in/out
        $this->assertEqualsWithDelta(90.0, $cost, 0.01);
    }
}
