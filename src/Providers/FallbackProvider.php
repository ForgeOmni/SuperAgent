<?php

namespace SuperAgent\Providers;

use Generator;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Exceptions\ProviderException;
use SuperAgent\StreamingHandler;

class FallbackProvider implements LLMProvider
{
    /**
     * @var array<LLMProvider>
     */
    protected array $providers;
    
    protected ?LLMProvider $lastSuccessfulProvider = null;
    protected array $errors = [];
    protected bool $throwOnAllFailures;
    protected bool $returnFirstSuccess;
    protected $onProviderSwitch;

    /**
     * @param array<LLMProvider|array{provider: LLMProvider, weight?: int}> $providers
     * @param array $config
     */
    public function __construct(array $providers, array $config = [])
    {
        if (empty($providers)) {
            throw new ProviderException('At least one provider is required for fallback', 'fallback');
        }

        $this->providers = $this->normalizeProviders($providers);
        $this->throwOnAllFailures = $config['throw_on_all_failures'] ?? true;
        $this->returnFirstSuccess = $config['return_first_success'] ?? true;
        $this->onProviderSwitch = $config['on_provider_switch'] ?? null;
    }

    /**
     * Normalize provider array to handle both simple arrays and weighted configurations.
     */
    protected function normalizeProviders(array $providers): array
    {
        $normalized = [];
        
        foreach ($providers as $provider) {
            if ($provider instanceof LLMProvider) {
                $normalized[] = $provider;
            } elseif (is_array($provider) && isset($provider['provider'])) {
                // Handle weighted provider configuration
                $weight = $provider['weight'] ?? 1;
                for ($i = 0; $i < $weight; $i++) {
                    $normalized[] = $provider['provider'];
                }
            } elseif (is_string($provider)) {
                // Handle provider name string
                $normalized[] = ProviderRegistry::createFromEnv($provider);
            } elseif (is_array($provider) && isset($provider['name'])) {
                // Handle provider configuration array
                $name = $provider['name'];
                unset($provider['name']);
                $normalized[] = ProviderRegistry::create($name, $provider);
            }
        }
        
        return $normalized;
    }

    /**
     * @inheritDoc
     */
    public function chat(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): Generator {
        $this->errors = [];
        $lastError = null;

        foreach ($this->providers as $index => $provider) {
            try {
                // Notify about provider switch
                if ($this->onProviderSwitch && $index > 0) {
                    ($this->onProviderSwitch)($provider, $index, $this->errors);
                }

                // Try current provider
                $result = yield from $provider->chat($messages, $tools, $systemPrompt, $options);
                
                // Success - remember this provider
                $this->lastSuccessfulProvider = $provider;
                
                if ($this->returnFirstSuccess) {
                    return $result;
                }
                
                // Continue to next provider if not returning first success
            } catch (ProviderException $e) {
                $this->errors[] = [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];
                $lastError = $e;
                
                // Continue to next provider
                continue;
            } catch (\Exception $e) {
                $this->errors[] = [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                    'code' => 0,
                ];
                $lastError = $e;
                
                // Continue to next provider
                continue;
            }
        }

        // All providers failed
        if ($this->throwOnAllFailures) {
            $errorMessages = array_map(
                fn($e) => "{$e['provider']}: {$e['error']}",
                $this->errors
            );
            
            throw new ProviderException(
                "All providers failed. Errors: " . implode('; ', $errorMessages),
                'fallback',
                previous: $lastError
            );
        }
        
        // Return empty result if not throwing
        yield from [];
    }

    /**
     * Add a provider to the fallback chain.
     */
    public function addProvider(LLMProvider $provider): static
    {
        $this->providers[] = $provider;
        return $this;
    }

    /**
     * Remove a provider from the fallback chain.
     */
    public function removeProvider(string $name): static
    {
        $this->providers = array_filter(
            $this->providers,
            fn($p) => $p->getName() !== $name
        );
        
        $this->providers = array_values($this->providers);
        return $this;
    }

    /**
     * Get all providers in the fallback chain.
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get the last successful provider.
     */
    public function getLastSuccessfulProvider(): ?LLMProvider
    {
        return $this->lastSuccessfulProvider;
    }

    /**
     * Get errors from last execution.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Reorder providers based on success rate.
     */
    public function optimizeOrder(): static
    {
        // If we have a last successful provider, move it to front
        if ($this->lastSuccessfulProvider) {
            $providers = [$this->lastSuccessfulProvider];
            
            foreach ($this->providers as $provider) {
                if ($provider !== $this->lastSuccessfulProvider) {
                    $providers[] = $provider;
                }
            }
            
            $this->providers = $providers;
        }
        
        return $this;
    }

    /**
     * Create a fallback provider with common configurations.
     */
    public static function createWithDefaults(): static
    {
        $providers = [];
        
        // Try to auto-discover providers
        $available = ProviderRegistry::discover();
        
        // Priority order
        $priority = ['anthropic', 'openai', 'openrouter', 'bedrock', 'ollama'];
        
        foreach ($priority as $name) {
            if (in_array($name, $available)) {
                try {
                    $providers[] = ProviderRegistry::createFromEnv($name);
                } catch (\Exception $e) {
                    // Skip unavailable provider
                }
            }
        }
        
        if (empty($providers)) {
            throw new ProviderException(
                'No providers available for fallback. Please configure at least one provider.',
                'fallback'
            );
        }
        
        return new static($providers);
    }

    /**
     * Create a cost-optimized fallback chain.
     */
    public static function createCostOptimized(): static
    {
        $providers = [];
        $available = ProviderRegistry::discover();
        
        // Order by cost (cheapest first)
        $costOrder = [
            'ollama',      // Free (local)
            'openrouter',  // Potentially cheaper with routing
            'anthropic',   // Claude Haiku for cheap option
            'openai',      // GPT-3.5-turbo for cheap option
            'bedrock',     // Pay per use
        ];
        
        foreach ($costOrder as $name) {
            if (in_array($name, $available)) {
                try {
                    $provider = ProviderRegistry::createFromEnv($name);
                    
                    // Set cheaper models
                    if ($name === 'anthropic') {
                        $provider->setModel('claude-3-haiku-20240307');
                    } elseif ($name === 'openai') {
                        $provider->setModel('gpt-3.5-turbo');
                    } elseif ($name === 'openrouter') {
                        $provider->setModel('anthropic/claude-3-haiku');
                    }
                    
                    $providers[] = $provider;
                } catch (\Exception $e) {
                    // Skip unavailable provider
                }
            }
        }
        
        return new static($providers);
    }

    /**
     * Create a quality-optimized fallback chain.
     */
    public static function createQualityOptimized(): static
    {
        $providers = [];
        $available = ProviderRegistry::discover();
        
        // Order by quality (best first)
        $qualityOrder = [
            'anthropic',   // Claude 3.5 Sonnet / Opus
            'openai',      // GPT-4o
            'openrouter',  // Access to multiple high-quality models
            'bedrock',     // Claude on AWS
            'ollama',      // Local models as last resort
        ];
        
        foreach ($qualityOrder as $name) {
            if (in_array($name, $available)) {
                try {
                    $provider = ProviderRegistry::createFromEnv($name);
                    
                    // Set best models
                    if ($name === 'anthropic') {
                        $provider->setModel('claude-3-5-sonnet-20241022');
                    } elseif ($name === 'openai') {
                        $provider->setModel('gpt-4o');
                    } elseif ($name === 'openrouter') {
                        $provider->setModel('anthropic/claude-3-5-sonnet');
                    }
                    
                    $providers[] = $provider;
                } catch (\Exception $e) {
                    // Skip unavailable provider
                }
            }
        }
        
        return new static($providers);
    }

    public function setModel(string $model): void
    {
        // Set model on all providers
        foreach ($this->providers as $provider) {
            $provider->setModel($model);
        }
        
    }

    public function getModel(): string
    {
        // Return model from first provider
        return $this->providers[0]->getModel();
    }

    public function getName(): string
    {
        return 'fallback';
    }

    public function name(): string
    {
        return 'fallback';
    }

    public function formatMessages(array $messages): array
    {
        // Use first provider's formatting
        if (!empty($this->providers)) {
            return $this->providers[0]->formatMessages($messages);
        }
        return [];
    }

    public function formatTools(array $tools): array
    {
        // Use first provider's formatting
        if (!empty($this->providers)) {
            return $this->providers[0]->formatTools($tools);
        }
        return [];
    }
}