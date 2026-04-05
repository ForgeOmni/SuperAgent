<?php

namespace SuperAgent\Messages;

class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $cacheCreationInputTokens = null,
        public readonly ?int $cacheReadInputTokens = null,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens
            + $this->outputTokens
            + ($this->cacheCreationInputTokens ?? 0)
            + ($this->cacheReadInputTokens ?? 0);
    }

    public function toArray(): array
    {
        return array_filter([
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
        ], fn ($v) => $v !== null);
    }
}
