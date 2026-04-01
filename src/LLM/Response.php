<?php

declare(strict_types=1);

namespace SuperAgent\LLM;

class Response
{
    public function __construct(
        public readonly string $content,
        public readonly array $usage = [],
        public readonly array $metadata = [],
    ) {}
    
    /**
     * Get total tokens used
     */
    public function getTotalTokens(): int
    {
        return $this->usage['total_tokens'] ?? 0;
    }
    
    /**
     * Get prompt tokens used
     */
    public function getPromptTokens(): int
    {
        return $this->usage['prompt_tokens'] ?? 0;
    }
    
    /**
     * Get completion tokens used
     */
    public function getCompletionTokens(): int
    {
        return $this->usage['completion_tokens'] ?? 0;
    }
}