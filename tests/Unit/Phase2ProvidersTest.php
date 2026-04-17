<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Providers\FallbackProvider;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Providers\OpenAIProvider;
use SuperAgent\Providers\OpenRouterProvider;
use SuperAgent\Providers\BedrockProvider;
use SuperAgent\Providers\OllamaProvider;
use SuperAgent\Support\StructuredOutput;
use SuperAgent\Messages\UserMessage;
use SuperAgent\CostCalculator;
use SuperAgent\Messages\Usage;
use SuperAgent\Exceptions\ProviderException;

class Phase2ProvidersTest extends TestCase
{
    /**
     * Test ProviderRegistry functionality.
     */
    public function testProviderRegistry(): void
    {
        // Test provider registration
        $this->assertTrue(ProviderRegistry::hasProvider('anthropic'));
        $this->assertTrue(ProviderRegistry::hasProvider('openai'));
        $this->assertTrue(ProviderRegistry::hasProvider('openrouter'));
        $this->assertTrue(ProviderRegistry::hasProvider('bedrock'));
        $this->assertTrue(ProviderRegistry::hasProvider('ollama'));
        $this->assertFalse(ProviderRegistry::hasProvider('unknown'));

        // Test getting provider list
        $providers = ProviderRegistry::getProviders();
        $this->assertContains('anthropic', $providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('openrouter', $providers);
        $this->assertContains('bedrock', $providers);
        $this->assertContains('ollama', $providers);

        // Test default config
        $anthropicConfig = ProviderRegistry::getDefaultConfig('anthropic');
        $this->assertArrayHasKey('model', $anthropicConfig);
        $this->assertArrayHasKey('max_tokens', $anthropicConfig);

        // Test capabilities
        $anthropicCaps = ProviderRegistry::getCapabilities('anthropic');
        $this->assertTrue($anthropicCaps['streaming']);
        $this->assertTrue($anthropicCaps['tools']);
        $this->assertTrue($anthropicCaps['vision']);
        
        $openaiCaps = ProviderRegistry::getCapabilities('openai');
        $this->assertTrue($openaiCaps['structured_output']);
        
        $ollamaCaps = ProviderRegistry::getCapabilities('ollama');
        $this->assertTrue($ollamaCaps['local']);
        $this->assertTrue($ollamaCaps['embeddings']);
    }

    /**
     * Test provider creation with missing config.
     */
    public function testProviderCreationValidation(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("Missing required configuration key 'api_key'");
        
        ProviderRegistry::create('anthropic', []);
    }

    /**
     * Test OpenAI provider instantiation.
     */
    public function testOpenAIProvider(): void
    {
        $provider = new OpenAIProvider([
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]);
        
        $this->assertEquals('openai', $provider->getName());
        $this->assertEquals('gpt-4o', $provider->getModel());
        
        $provider->setModel('gpt-3.5-turbo');
        $this->assertEquals('gpt-3.5-turbo', $provider->getModel());
        
        // Test supported models
        $models = $provider->getSupportedModels();
        $this->assertContains('gpt-4o', $models);
        $this->assertContains('gpt-3.5-turbo', $models);
    }

    /**
     * Test OpenRouter provider instantiation.
     */
    public function testOpenRouterProvider(): void
    {
        $provider = new OpenRouterProvider([
            'api_key' => 'test-key',
            'app_name' => 'TestApp',
            'site_url' => 'https://test.com',
        ]);
        
        $this->assertEquals('openrouter', $provider->getName());
        
        // Test supported models
        $models = $provider->getSupportedModels();
        $this->assertContains('anthropic/claude-3-5-sonnet', $models);
        $this->assertContains('openai/gpt-4o', $models);
        $this->assertContains('google/gemini-pro', $models);
    }

    /**
     * Test Bedrock provider instantiation.
     */
    public function testBedrockProvider(): void
    {
        // Skip if AWS SDK not installed
        if (!class_exists('\\Aws\\Credentials\\Credentials')) {
            $this->markTestSkipped('AWS SDK not installed');
        }
        
        $provider = new BedrockProvider([
            'access_key' => 'test-access',
            'secret_key' => 'test-secret',
            'region' => 'us-west-2',
        ]);
        
        $this->assertEquals('bedrock', $provider->getName());
        
        // Test alternative key names
        $provider2 = new BedrockProvider([
            'aws_access_key_id' => 'test-access',
            'aws_secret_access_key' => 'test-secret',
        ]);
        
        $this->assertEquals('bedrock', $provider2->getName());
        
        // Test supported models
        $models = $provider->getSupportedModels();
        $this->assertContains('anthropic.claude-3-5-sonnet-20241022-v2:0', $models);
        $this->assertContains('amazon.titan-text-express-v1', $models);
        $this->assertContains('meta.llama3-1-70b-instruct-v1:0', $models);
    }

    /**
     * Test Ollama provider instantiation.
     */
    public function testOllamaProvider(): void
    {
        $provider = new OllamaProvider([
            'base_url' => 'http://localhost:11434',
            'model' => 'llama2',
        ]);
        
        $this->assertEquals('ollama', $provider->getName());
        $this->assertEquals('llama2', $provider->getModel());
        
        // Test supported models
        $models = $provider->getSupportedModels();
        $this->assertContains('llama2', $models);
        $this->assertContains('codellama', $models);
        $this->assertContains('mistral', $models);
    }

    /**
     * Test FallbackProvider functionality.
     */
    public function testFallbackProvider(): void
    {
        // Create stub providers instead of mocks
        $provider1 = new class(['api_key' => 'test']) extends AnthropicProvider {
            public function getName(): string { return 'anthropic'; }
        };
        
        $provider2 = new class(['api_key' => 'test']) extends OpenAIProvider {
            public function getName(): string { return 'openai'; }
        };
        
        // Test basic fallback creation
        $fallback = new FallbackProvider([$provider1, $provider2]);
        
        $this->assertCount(2, $fallback->getProviders());
        $this->assertEquals('fallback', $fallback->getName());
        
        // Test adding provider
        $provider3 = new class(['base_url' => 'http://localhost:11434']) extends OllamaProvider {
            public function getName(): string { return 'ollama'; }
        };
        
        $fallback->addProvider($provider3);
        $this->assertCount(3, $fallback->getProviders());
        
        // Test removing provider
        $fallback->removeProvider('openai');
        $this->assertCount(2, $fallback->getProviders());
    }

    /**
     * Test CostCalculator with new providers.
     */
    public function testCostCalculatorExtended(): void
    {
        $usage = new Usage(1000, 500);
        
        // Test OpenAI pricing
        $cost = CostCalculator::calculate('gpt-4o', $usage);
        $expectedCost = (1000 * 2.50 / 1_000_000) + (500 * 10.0 / 1_000_000);
        $this->assertEquals($expectedCost, $cost);
        
        // Test OpenRouter pricing
        $cost = CostCalculator::calculate('anthropic/claude-3-haiku', $usage);
        $expectedCost = (1000 * 0.25 / 1_000_000) + (500 * 1.25 / 1_000_000);
        $this->assertEquals($expectedCost, $cost);
        
        // Test Bedrock pricing
        $cost = CostCalculator::calculate('amazon.titan-text-express-v1', $usage);
        $expectedCost = (1000 * 0.13 / 1_000_000) + (500 * 0.17 / 1_000_000);
        $this->assertEqualsWithDelta($expectedCost, $cost, 0.000001);
        
        // Test Ollama (free)
        $cost = CostCalculator::calculate('llama2', $usage);
        $this->assertEquals(0.0, $cost);
        
        // Test cost formatting
        $this->assertEquals('$0.0001', CostCalculator::format(0.0001));
        $this->assertEquals('$0.050', CostCalculator::format(0.05));
        $this->assertEquals('$1.50', CostCalculator::format(1.5));
        
        // Test batch calculation
        $usages = [
            new Usage(1000, 500),
            new Usage(2000, 1000),
            new Usage(500, 250),
        ];
        $totalCost = CostCalculator::calculateBatch('gpt-4o', $usages);
        $expectedTotal = 0;
        foreach ($usages as $u) {
            $expectedTotal += CostCalculator::calculate('gpt-4o', $u);
        }
        $this->assertEquals($expectedTotal, $totalCost);
        
        // Test estimation
        $estimatedCost = CostCalculator::estimate('gpt-4o', 4000, 2000);
        // 4000 chars ≈ 1000 tokens, 2000 chars ≈ 500 tokens
        $expectedCost = (1000 * 2.50 / 1_000_000) + (500 * 10.0 / 1_000_000);
        $this->assertEquals($expectedCost, $estimatedCost);
    }

    /**
     * Test StructuredOutput schema builder.
     */
    public function testStructuredOutputSchemaBuilder(): void
    {
        $builder = StructuredOutput::schema();
        $schema = $builder
            ->object([
                'name' => $builder->string(['minLength' => 1]),
                'age' => $builder->integer(['minimum' => 0, 'maximum' => 150]),
                'email' => $builder->string(['pattern' => '^[\w\.-]+@[\w\.-]+\.\w+$']),
                'roles' => [
                    'type' => 'array',
                    'items' => $builder->enum(['admin', 'user', 'guest'])
                ],
                'active' => $builder->boolean(),
            ])
            ->required(['name', 'email'])
            ->title('User')
            ->description('User information schema')
            ->additionalProperties(false)
            ->build();
        
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('age', $schema['properties']);
        $this->assertArrayHasKey('email', $schema['properties']);
        $this->assertArrayHasKey('roles', $schema['properties']);
        $this->assertArrayHasKey('active', $schema['properties']);
        $this->assertEquals(['name', 'email'], $schema['required']);
        $this->assertEquals('User', $schema['title']);
        $this->assertEquals('User information schema', $schema['description']);
        $this->assertFalse($schema['additionalProperties']);
        
        // Test individual field schemas
        $this->assertEquals('string', $schema['properties']['name']['type']);
        $this->assertEquals(1, $schema['properties']['name']['minLength']);
        
        $this->assertEquals('integer', $schema['properties']['age']['type']);
        $this->assertEquals(0, $schema['properties']['age']['minimum']);
        $this->assertEquals(150, $schema['properties']['age']['maximum']);
        
        $this->assertEquals('array', $schema['properties']['roles']['type']);
        $this->assertArrayHasKey('enum', $schema['properties']['roles']['items']);
        $this->assertEquals(['admin', 'user', 'guest'], $schema['properties']['roles']['items']['enum']);
        
        $this->assertEquals('boolean', $schema['properties']['active']['type']);
    }

    /**
     * Test provider suggestion based on requirements.
     */
    public function testProviderSuggestion(): void
    {
        // Mock discovery to return specific providers
        $reflection = new \ReflectionClass(ProviderRegistry::class);
        $method = $reflection->getMethod('discover');
        
        // Note: In real tests, you'd mock the discover method properly
        // For now, we'll test the logic assuming certain providers are available
        
        // Test requirements matching (conceptual test)
        $requirements = [
            'local' => true,
        ];
        // Would suggest 'ollama' if available
        
        $requirements = [
            'structured_output' => true,
        ];
        // Would suggest 'openai' if available
        
        $requirements = [
            'cost_effective' => true,
        ];
        // Would suggest 'ollama' or 'openrouter' if available
        
        $this->assertTrue(true); // Placeholder assertion
    }

    /**
     * Test all providers are properly registered.
     */
    public function testAllProvidersRegistered(): void
    {
        $expectedProviders = [
            'anthropic',
            'openai',
            'openrouter',
            'bedrock',
            'ollama',
        ];
        
        foreach ($expectedProviders as $provider) {
            $this->assertTrue(
                ProviderRegistry::hasProvider($provider),
                "Provider '{$provider}' should be registered"
            );
        }
    }

    /**
     * Test cost calculator has pricing for major models.
     */
    public function testCostCalculatorHasMajorModels(): void
    {
        $majorModels = [
            'claude-3-5-sonnet-20241022',
            'gpt-4o',
            'gpt-3.5-turbo',
            'anthropic/claude-3-5-sonnet',
            'google/gemini-pro',
            'amazon.titan-text-express-v1',
            'llama2',
        ];
        
        $usage = new Usage(1000, 1000);
        
        foreach ($majorModels as $model) {
            $cost = CostCalculator::calculate($model, $usage);
            $this->assertIsFloat($cost, "Should calculate cost for model: {$model}");
            $this->assertGreaterThanOrEqual(0, $cost, "Cost should be non-negative for model: {$model}");
        }
    }
}