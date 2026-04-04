<?php

namespace SuperAgent\IncrementalContext;

/**
 * Summary of the current incremental context state.
 */
class ContextSummary
{
    public function __construct(
        public readonly int $messageCount,
        public readonly int $totalTokens,
        public readonly array $checkpoints,
        public readonly ?string $lastCheckpoint,
        public readonly float $compressionRatio,
        public readonly int $tokensSaved
    ) {}

    public function toArray(): array
    {
        return [
            'message_count' => $this->messageCount,
            'total_tokens' => $this->totalTokens,
            'checkpoints' => $this->checkpoints,
            'last_checkpoint' => $this->lastCheckpoint,
            'compression_ratio' => $this->compressionRatio,
            'tokens_saved' => $this->tokensSaved,
        ];
    }
}
