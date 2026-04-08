<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\OllamaProvider;

class AgentProviderResolutionTest extends TestCase
{
    /**
     * Test that passing a provider instance directly is used as-is.
     */
    public function test_resolve_provider_instance_directly(): void
    {
        $provider = new AnthropicProvider(['api_key' => 'test-key']);

        $agent = new Agent(['provider' => $provider]);

        $this->assertSame($provider, $agent->getProvider());
    }

    /**
     * Test default provider resolves to anthropic.
     */
    public function test_default_provider_is_anthropic(): void
    {
        $agent = new Agent(['api_key' => 'test-key']);

        $this->assertInstanceOf(AnthropicProvider::class, $agent->getProvider());
    }

    /**
     * Test explicit anthropic provider name.
     */
    public function test_explicit_anthropic_provider(): void
    {
        $agent = new Agent([
            'provider' => 'anthropic',
            'api_key' => 'test-key',
        ]);

        $this->assertInstanceOf(AnthropicProvider::class, $agent->getProvider());
    }

    /**
     * Test driver field allows named instances to use a different provider class.
     * Simulates a named provider like 'my-proxy' with driver 'anthropic'.
     */
    public function test_driver_field_resolves_correct_provider_class(): void
    {
        // Without Laravel config(), providerConfig will be empty,
        // so we pass a provider instance built with the driver pattern manually.
        // To truly test resolveProvider with driver, we use a subclass that exposes config.
        $agent = new TestableAgent([
            'provider' => 'my-proxy',
            'providerConfigs' => [
                'my-proxy' => [
                    'driver' => 'anthropic',
                    'api_key' => 'proxy-key',
                    'base_url' => 'https://proxy.example.com',
                ],
            ],
        ]);

        $this->assertInstanceOf(AnthropicProvider::class, $agent->getProvider());
        $this->assertEquals('anthropic', $agent->getProvider()->name());
    }

    /**
     * Test driver field with openai driver.
     */
    public function test_driver_field_with_openai(): void
    {
        $agent = new TestableAgent([
            'provider' => 'deepseek',
            'providerConfigs' => [
                'deepseek' => [
                    'driver' => 'openai',
                    'api_key' => 'deepseek-key',
                    'base_url' => 'https://api.deepseek.com',
                    'model' => 'deepseek-chat',
                ],
            ],
        ]);

        $this->assertInstanceOf(OpenAIProvider::class, $agent->getProvider());
    }

    /**
     * Test driver field with ollama driver (no api_key required).
     */
    public function test_driver_field_with_ollama(): void
    {
        $agent = new TestableAgent([
            'provider' => 'local-llm',
            'providerConfigs' => [
                'local-llm' => [
                    'driver' => 'ollama',
                    'base_url' => 'http://localhost:11434',
                    'model' => 'llama3',
                ],
            ],
        ]);

        $this->assertInstanceOf(OllamaProvider::class, $agent->getProvider());
    }

    /**
     * Test that provider name without driver uses name as driver (backward compat).
     */
    public function test_provider_name_used_as_driver_when_no_driver_field(): void
    {
        $agent = new TestableAgent([
            'provider' => 'anthropic',
            'providerConfigs' => [
                'anthropic' => [
                    'api_key' => 'test-key',
                ],
            ],
        ]);

        $this->assertInstanceOf(AnthropicProvider::class, $agent->getProvider());
    }

    /**
     * Test unsupported driver throws exception.
     */
    public function test_unsupported_driver_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported provider driver: nonexistent');

        new TestableAgent([
            'provider' => 'custom',
            'providerConfigs' => [
                'custom' => [
                    'driver' => 'nonexistent',
                    'api_key' => 'test-key',
                ],
            ],
        ]);
    }

    /**
     * Test model override via config is passed through.
     */
    public function test_model_override_passed_to_provider(): void
    {
        $agent = new Agent([
            'api_key' => 'test-key',
            'model' => 'claude-3-haiku-20240307',
        ]);

        // ModelResolver resolves old haiku ID to the latest haiku model
        $model = $agent->getProvider()->getModel();
        $this->assertTrue(
            str_contains($model, 'haiku'),
            "Expected a haiku model, got: {$model}"
        );
    }

    /**
     * Test multiple agents with different named providers.
     */
    public function test_multiple_agents_with_different_providers(): void
    {
        $agent1 = new Agent([
            'provider' => new AnthropicProvider(['api_key' => 'key-1']),
        ]);

        $agent2 = new Agent([
            'provider' => new OpenAIProvider(['api_key' => 'key-2']),
        ]);

        $this->assertInstanceOf(AnthropicProvider::class, $agent1->getProvider());
        $this->assertInstanceOf(OpenAIProvider::class, $agent2->getProvider());
        $this->assertNotSame($agent1->getProvider(), $agent2->getProvider());
    }
}

/**
 * Testable Agent subclass that allows injecting provider configs
 * without requiring Laravel's config() function.
 */
class TestableAgent extends Agent
{
    private array $providerConfigs = [];

    public function __construct(array $config = [])
    {
        if (isset($config['providerConfigs'])) {
            $this->providerConfigs = $config['providerConfigs'];
            unset($config['providerConfigs']);
        }

        parent::__construct($config);
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        // Parse key like "superagent.providers.my-proxy"
        static $instance = null;

        // We need instance context, but config() is static.
        // Use a workaround: store configs in a static var during construction.
        return self::$staticConfigs[$key] ?? $default;
    }

    private static array $staticConfigs = [];

    protected function resolveProvider(array $config): LLMProvider
    {
        // Populate static configs from instance providerConfigs
        foreach ($this->providerConfigs as $name => $providerConfig) {
            self::$staticConfigs["superagent.providers.{$name}"] = $providerConfig;
        }

        return parent::resolveProvider($config);
    }
}
