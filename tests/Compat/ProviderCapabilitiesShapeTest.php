<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Compat;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ProviderRegistry;

/**
 * Snapshot-lock `ProviderRegistry::getCapabilities()` for every shipped provider.
 *
 * Future work (CapabilityRouter, FeatureAdapter) will extend the capability
 * model but MUST NOT silently drop or rename keys that existing callers may
 * read. This test pins the exact current map — if a key needs to go, the
 * removal has to be an explicit diff against this fixture, not a side-effect.
 */
class ProviderCapabilitiesShapeTest extends TestCase
{
    public function test_anthropic_capabilities_shape(): void
    {
        $this->assertSame([
            'streaming'         => true,
            'tools'             => true,
            'vision'            => true,
            'max_context'       => 200000,
            'structured_output' => false,
        ], ProviderRegistry::getCapabilities('anthropic'));
    }

    public function test_openai_capabilities_shape(): void
    {
        $this->assertSame([
            'streaming'         => true,
            'tools'             => true,
            'vision'            => true,
            'max_context'       => 128000,
            'structured_output' => true,
        ], ProviderRegistry::getCapabilities('openai'));
    }

    public function test_openrouter_capabilities_shape(): void
    {
        $caps = ProviderRegistry::getCapabilities('openrouter');
        $this->assertTrue($caps['streaming']);
        $this->assertTrue($caps['tools']);
        $this->assertTrue($caps['vision']);
        $this->assertSame('varies', $caps['max_context']);
        $this->assertSame('varies', $caps['structured_output']);
        $this->assertTrue($caps['multi_provider']);
    }

    public function test_bedrock_capabilities_shape(): void
    {
        $caps = ProviderRegistry::getCapabilities('bedrock');
        $this->assertTrue($caps['streaming']);
        $this->assertSame('model_dependent', $caps['tools']);
        $this->assertSame('model_dependent', $caps['vision']);
        $this->assertFalse($caps['structured_output']);
        $this->assertTrue($caps['multi_model']);
    }

    public function test_ollama_capabilities_shape(): void
    {
        $caps = ProviderRegistry::getCapabilities('ollama');
        $this->assertTrue($caps['streaming']);
        $this->assertFalse($caps['tools']);
        $this->assertSame('model_dependent', $caps['vision']);
        $this->assertFalse($caps['structured_output']);
        $this->assertTrue($caps['local']);
        $this->assertTrue($caps['embeddings']);
    }

    public function test_gemini_capabilities_shape(): void
    {
        $this->assertSame([
            'streaming'         => true,
            'tools'             => true,
            'vision'            => true,
            'max_context'       => 1_048_576,
            'structured_output' => true,
        ], ProviderRegistry::getCapabilities('gemini'));
    }

    public function test_unknown_provider_returns_empty_array(): void
    {
        $this->assertSame([], ProviderRegistry::getCapabilities('not-a-provider'));
    }

    public function test_all_shipped_providers_are_registered(): void
    {
        $providers = ProviderRegistry::getProviders();
        $this->assertContains('anthropic', $providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('openrouter', $providers);
        $this->assertContains('bedrock', $providers);
        $this->assertContains('ollama', $providers);
        $this->assertContains('gemini', $providers);
    }
}
