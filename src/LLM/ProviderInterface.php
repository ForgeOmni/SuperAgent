<?php

declare(strict_types=1);

namespace SuperAgent\LLM;

interface ProviderInterface
{
    /**
     * Generate a response from the LLM
     * 
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Additional options like max_tokens, temperature, model
     * @return Response
     */
    public function generateResponse(array $messages, array $options = []): Response;
    
    /**
     * Get the provider name
     */
    public function getName(): string;
    
    /**
     * Check if the provider supports a specific model
     */
    public function supportsModel(string $model): bool;
}