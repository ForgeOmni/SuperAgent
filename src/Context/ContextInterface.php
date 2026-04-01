<?php

declare(strict_types=1);

namespace SuperAgent\Context;

/**
 * Interface for agent context management.
 */
interface ContextInterface
{
    /**
     * Add a message to the context.
     */
    public function addMessage(Message $message): void;
    
    /**
     * Get all messages in the context.
     * 
     * @return Message[]
     */
    public function getMessages(): array;
    
    /**
     * Clear all messages.
     */
    public function clearMessages(): void;
    
    /**
     * Set metadata value.
     */
    public function setMetadata(string $key, mixed $value): void;
    
    /**
     * Get metadata value or all metadata if key is null.
     */
    public function getMetadata(?string $key = null): mixed;
    
    /**
     * Check if metadata key exists.
     */
    public function hasMetadata(string $key): bool;
    
    /**
     * Remove metadata value.
     */
    public function removeMetadata(string $key): void;
    
    /**
     * Get the last message in the context.
     */
    public function getLastMessage(): ?Message;
    
    /**
     * Get the count of messages.
     */
    public function getMessageCount(): int;
}