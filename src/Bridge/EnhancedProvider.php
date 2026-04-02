<?php

declare(strict_types=1);

namespace SuperAgent\Bridge;

use Generator;
use SuperAgent\Bridge\Enhancers\EnhancerInterface;
use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Messages\AssistantMessage;

/**
 * Provider-agnostic decorator that applies CC optimization enhancers
 * before/after each LLM call.
 *
 * Wraps ANY LLMProvider (Anthropic, OpenAI, Bedrock, Ollama, OpenRouter)
 * and injects the enhancement pipeline transparently.
 *
 * Usage:
 *   $enhanced = new EnhancedProvider(
 *       inner: new AnthropicProvider($config),  // or OpenAIProvider, etc.
 *       enhancers: [new SystemPromptEnhancer(), new BashSecurityEnhancer()],
 *   );
 *   $agent = new Agent(['provider' => $enhanced]);
 */
class EnhancedProvider implements LLMProvider
{
    /** @var EnhancerInterface[] */
    private array $enhancers;

    /**
     * @param LLMProvider $inner Any LLM provider (Anthropic, OpenAI, Bedrock, Ollama, OpenRouter)
     * @param EnhancerInterface[] $enhancers Ordered list of enhancers to apply
     */
    public function __construct(
        private readonly LLMProvider $inner,
        array $enhancers = [],
    ) {
        $this->enhancers = $enhancers;
    }

    public function getInner(): LLMProvider
    {
        return $this->inner;
    }

    public function addEnhancer(EnhancerInterface $enhancer): static
    {
        $this->enhancers[] = $enhancer;

        return $this;
    }

    /**
     * @return EnhancerInterface[]
     */
    public function getEnhancers(): array
    {
        return $this->enhancers;
    }

    /**
     * Send a chat request with CC enhancements applied.
     *
     * Flow:
     *  1. Each enhancer modifies the request (messages, tools, systemPrompt, options)
     *  2. The inner provider makes the actual API call to any backend
     *  3. Each enhancer post-processes the response
     */
    public function chat(
        array $messages,
        array $tools = [],
        ?string $systemPrompt = null,
        array $options = [],
    ): Generator {
        // Phase 1: Pre-request enhancement pipeline
        foreach ($this->enhancers as $enhancer) {
            $enhancer->enhanceRequest($messages, $tools, $systemPrompt, $options);
        }

        // Phase 2: Delegate to inner provider (any backend)
        $generator = $this->inner->chat($messages, $tools, $systemPrompt, $options);

        // Phase 3: Post-response enhancement pipeline
        foreach ($generator as $assistantMessage) {
            foreach ($this->enhancers as $enhancer) {
                $assistantMessage = $enhancer->enhanceResponse($assistantMessage);
            }

            yield $assistantMessage;
        }
    }

    public function formatMessages(array $messages): array
    {
        return $this->inner->formatMessages($messages);
    }

    public function formatTools(array $tools): array
    {
        return $this->inner->formatTools($tools);
    }

    public function getModel(): string
    {
        return $this->inner->getModel();
    }

    public function setModel(string $model): void
    {
        $this->inner->setModel($model);
    }

    public function name(): string
    {
        return 'enhanced_' . $this->inner->name();
    }
}
