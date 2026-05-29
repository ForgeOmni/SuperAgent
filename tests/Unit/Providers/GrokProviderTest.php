<?php

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\CostCalculator;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\Messages\Usage;
use SuperAgent\Providers\ChatCompletionsProvider;
use SuperAgent\Providers\GrokProvider;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Builtin\WorkflowTool;

/**
 * xAI Grok provider support: registry wiring, catalog pricing / alias
 * resolution (verified against docs.x.ai — grok-4.3 flagship at $1.25/$2.50,
 * api.x.ai/v1), OpenAI tool-format reuse, env discovery, reasoning_effort
 * gating. Also pins that Cursor Composer support was removed (it has no
 * official public API).
 */
class GrokProviderTest extends TestCase
{
    public function test_registry_creates_grok(): void
    {
        $p = ProviderRegistry::create('grok', ['api_key' => 'k']);
        $this->assertInstanceOf(GrokProvider::class, $p);
        $this->assertInstanceOf(ChatCompletionsProvider::class, $p);
        $this->assertSame('grok', $p->name());
        $this->assertSame('grok-4.3', $p->getModel());
    }

    public function test_provider_registered_with_capabilities(): void
    {
        $this->assertContains('grok', ProviderRegistry::getProviders());
        $this->assertSame(1_000_000, ProviderRegistry::getCapabilities('grok')['max_context']);
    }

    public function test_catalog_pricing_and_alias_resolution(): void
    {
        $this->assertSame(['input' => 1.25, 'output' => 2.50], ModelCatalog::pricing('grok-4.3'));
        $this->assertSame(['input' => 3.0, 'output' => 15.0], ModelCatalog::pricing('grok-4'));
        // `grok` alias resolves to the newest in the grok family — grok-4.3.
        $this->assertSame('grok-4.3', ModelCatalog::resolveAlias('grok'));
    }

    public function test_cost_calculator_pricing(): void
    {
        $oneM = new Usage(1_000_000, 1_000_000);
        $this->assertEqualsWithDelta(3.75, CostCalculator::calculate('grok-4.3', $oneM), 0.001);
        $this->assertEqualsWithDelta(18.0, CostCalculator::calculate('grok-4', $oneM), 0.001);
        $this->assertEqualsWithDelta(0.70, CostCalculator::calculate('grok-4-fast', $oneM), 0.001);
    }

    public function test_tools_use_openai_function_shape(): void
    {
        $p = ProviderRegistry::create('grok', ['api_key' => 'k']);
        $formatted = $p->formatTools([new WorkflowTool()]);
        $this->assertCount(1, $formatted);
        $this->assertSame('function', $formatted[0]['type']);
        $this->assertSame('workflow', $formatted[0]['function']['name']);
        $this->assertArrayHasKey('parameters', $formatted[0]['function']);
    }

    public function test_discover_picks_up_env_key(): void
    {
        $prev = getenv('XAI_API_KEY');
        putenv('XAI_API_KEY=test');
        try {
            $this->assertContains('grok', ProviderRegistry::discover());
        } finally {
            $prev === false ? putenv('XAI_API_KEY') : putenv('XAI_API_KEY=' . $prev);
        }
    }

    public function test_reasoning_effort_only_emitted_for_mini(): void
    {
        // grok-3-mini accepts reasoning_effort; the flagship reasons natively
        // and rejects the param, so the fragment must be empty there.
        $mini = new GrokProvider(['api_key' => 'k', 'model' => 'grok-3-mini']);
        $this->assertSame(['reasoning_effort' => 'high'], $mini->reasoningEffortFragment('high'));
        $this->assertSame(['reasoning_effort' => 'low'], $mini->reasoningEffortFragment('low'));

        $flagship = new GrokProvider(['api_key' => 'k', 'model' => 'grok-4.3']);
        $this->assertSame([], $flagship->reasoningEffortFragment('high'));
    }

    public function test_cursor_composer_support_removed(): void
    {
        // Cursor Composer has no official public OpenAI-compatible API, so it
        // is intentionally not a provider.
        $this->assertNotContains('cursor', ProviderRegistry::getProviders());
        $this->assertNull(ModelCatalog::resolveAlias('cursor'));
        $this->assertNull(ModelCatalog::resolveAlias('composer'));
        $this->assertNull(ModelCatalog::pricing('composer-1'));

        $this->expectException(ProviderException::class);
        ProviderRegistry::create('cursor', ['api_key' => 'k']);
    }
}
