<?php

declare(strict_types=1);

namespace SuperAgent\Context\Strategies;

use SuperAgent\Context\Message;

interface CompressionStrategy
{
    /**
     * Get the priority of this strategy (lower number = higher priority)
     */
    public function getPriority(): int;
    
    /**
     * Check if this strategy can compress the given messages
     */
    public function canCompress(array $messages, array $context = []): bool;
    
    /**
     * Compress the messages and return the result
     * 
     * @param Message[] $messages
     * @return CompressionResult
     */
    public function compress(array $messages, array $options = []): CompressionResult;
    
    /**
     * Get the name of this strategy
     */
    public function getName(): string;
}

class CompressionResult
{
    public function __construct(
        public readonly array $compressedMessages,
        public readonly array $preservedMessages,
        public readonly ?Message $boundaryMessage = null,
        public readonly ?array $attachments = [],
        public readonly int $tokensSaved = 0,
        public readonly int $preCompactTokenCount = 0,
        public readonly int $postCompactTokenCount = 0,
        public readonly array $metadata = [],
    ) {}
    
    /**
     * Check if compression was successful
     */
    public function isSuccessful(): bool
    {
        return $this->tokensSaved > 0;
    }
    
    /**
     * Get all messages in order (boundary + compressed + preserved)
     */
    public function getAllMessages(): array
    {
        $messages = [];
        
        if ($this->boundaryMessage !== null) {
            $messages[] = $this->boundaryMessage;
        }
        
        $messages = array_merge($messages, $this->compressedMessages);
        $messages = array_merge($messages, $this->preservedMessages);
        $messages = array_merge($messages, $this->attachments);
        
        return $messages;
    }
}