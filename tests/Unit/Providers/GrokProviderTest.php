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
 * resolution (verified against docs.x.ai — grok-4.5 flagship at $2/$6,
 * 500K ctx, api.x.ai/v1), OpenAI tool-format reuse, env discovery,
 * reasoning_effort gating (grok-4.5 three-level dial, mini two-level,
 * everything else none) and x-grok-conv-id cache pinning. Also pins that
 * Cursor Composer support was removed (it has no official public API).
 */
class GrokProviderTest extends TestCase
{
    public function test_registry_creates_grok(): void
    {
        $p = ProviderRegistry::create('grok', ['api_key' => 'k']);
        $this->assertInstanceOf(GrokProvider::class, $p);
        $this->assertInstanceOf(ChatCompletionsProvider::class, $p);
        $this->assertSame('grok', $p->name());
        $this->assertSame('grok-4.5', $p->getModel());
    }

    public function test_provider_registered_with_capabilities(): void
    {
        $this->assertContains('grok', ProviderRegistry::getProviders());
        // grok-4.5 flagship window (grok-4.3/grok-4-fast go higher per-model).
        $this->assertSame(500_000, ProviderRegistry::getCapabilities('grok')['max_context']);
    }

    public function test_catalog_pricing_and_alias_resolution(): void
    {
        $grok45 = ModelCatalog::pricing('grok-4.5');
        $this->assertSame(2.00, $grok45['input']);
        $this->assertSame(6.00, $grok45['output']);
        $this->assertSame(['input' => 1.25, 'output' => 2.50], ModelCatalog::pricing('grok-4.3'));
        $this->assertSame(['input' => 3.0, 'output' => 15.0], ModelCatalog::pricing('grok-4'));
        // `grok` alias resolves to the newest in the grok family — grok-4.5.
        $this->assertSame('grok-4.5', ModelCatalog::resolveAlias('grok'));
        $this->assertSame('grok-4.5', ModelCatalog::resolveAlias('grok-4.5-latest'));
    }

    public function test_cost_calculator_pricing(): void
    {
        $oneM = new Usage(1_000_000, 1_000_000);
        $this->assertEqualsWithDelta(8.0, CostCalculator::calculate('grok-4.5', $oneM), 0.001);
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

    public function test_reasoning_effort_three_level_dial_on_grok_45(): void
    {
        // grok-4.5 takes the low|medium|high dial (server default high);
        // reasoning cannot be disabled, so `off` sends nothing.
        $p = new GrokProvider(['api_key' => 'k', 'model' => 'grok-4.5']);
        $this->assertSame(['reasoning_effort' => 'low'], $p->reasoningEffortFragment('low'));
        $this->assertSame(['reasoning_effort' => 'medium'], $p->reasoningEffortFragment('medium'));
        $this->assertSame(['reasoning_effort' => 'high'], $p->reasoningEffortFragment('high'));
        // max/xhigh clamp down to xAI's top tier.
        $this->assertSame(['reasoning_effort' => 'high'], $p->reasoningEffortFragment('max'));
        $this->assertSame([], $p->reasoningEffortFragment('off'));
    }

    public function test_reasoning_effort_two_level_for_mini_and_none_for_other_flagships(): void
    {
        // grok-3-mini keeps the older two-level dial; grok-4.3/grok-4 reason
        // natively and reject the param, so the fragment must be empty there.
        $mini = new GrokProvider(['api_key' => 'k', 'model' => 'grok-3-mini']);
        $this->assertSame(['reasoning_effort' => 'high'], $mini->reasoningEffortFragment('high'));
        $this->assertSame(['reasoning_effort' => 'low'], $mini->reasoningEffortFragment('low'));
        $this->assertSame(['reasoning_effort' => 'low'], $mini->reasoningEffortFragment('medium'));

        $previous = new GrokProvider(['api_key' => 'k', 'model' => 'grok-4.3']);
        $this->assertSame([], $previous->reasoningEffortFragment('high'));
    }

    public function test_conversation_id_pins_cache_via_header(): void
    {
        // xAI cache routing: conversation_id (or prompt_cache_key) becomes
        // the x-grok-conv-id header on the Chat Completions surface.
        $p = new GrokProvider(['api_key' => 'k', 'conversation_id' => 'conv-123']);
        $ref = new \ReflectionObject($p);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);
        $headers = $prop->getValue($p)->getConfig('headers');
        $this->assertSame('conv-123', $headers['x-grok-conv-id'] ?? null);

        $bare = new GrokProvider(['api_key' => 'k']);
        $bareHeaders = $prop->getValue($bare)->getConfig('headers');
        $this->assertArrayNotHasKey('x-grok-conv-id', $bareHeaders);
    }

    public function test_cursor_is_catalog_reference_only_never_a_provider(): void
    {
        // Cursor has no official public OpenAI-compatible API, so it is
        // intentionally NOT a callable provider. Since 1.1.8 its managed
        // models (composer-2.5, cursor-grok-4.5-high) exist as catalog-only
        // reference entries — subscription-billed, dispatched via the
        // cursor-agent CLI — but the registry must keep refusing 'cursor'.
        $this->assertNotContains('cursor', ProviderRegistry::getProviders());
        $this->assertNull(ModelCatalog::resolveAlias('cursor'));
        $this->assertSame('composer-2.5', ModelCatalog::resolveAlias('composer'));
        $this->assertNull(ModelCatalog::pricing('composer-2.5')); // no per-token price: subscription-only
        $this->assertNull(ModelCatalog::pricing('composer-1'));

        $this->expectException(ProviderException::class);
        ProviderRegistry::create('cursor', ['api_key' => 'k']);
    }
}
