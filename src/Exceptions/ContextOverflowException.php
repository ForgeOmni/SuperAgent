<?php

declare(strict_types=1);

namespace SuperAgent\Exceptions;

/**
 * Context window overflow — messages exceed the model's token limit.
 */
class ContextOverflowException extends AgentException
{
    public function __construct(
        public readonly int $tokens = 0,
        public readonly int $maxTokens = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Context overflow: %d tokens exceeds %d limit', $tokens, $maxTokens),
            previous: $previous,
            context: ['tokens' => $tokens, 'max_tokens' => $maxTokens],
        );
    }

    public function isRetryable(): bool
    {
        return true; // can retry after compression
    }
}
