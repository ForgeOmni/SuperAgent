<?php

declare(strict_types=1);

namespace SuperAgent\Context;

/**
 * Basic context implementation for agents.
 */
class Context implements ContextInterface
{
    private array $messages = [];
    private array $metadata = [];
    
    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }
    
    public function getMessages(): array
    {
        return $this->messages;
    }
    
    public function clearMessages(): void
    {
        $this->messages = [];
    }
    
    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }
    
    public function getMetadata(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }
        
        return $this->metadata[$key] ?? null;
    }
    
    public function hasMetadata(string $key): bool
    {
        return isset($this->metadata[$key]);
    }
    
    public function removeMetadata(string $key): void
    {
        unset($this->metadata[$key]);
    }
    
    public function getLastMessage(): ?Message
    {
        if (empty($this->messages)) {
            return null;
        }
        
        return end($this->messages);
    }
    
    public function getMessageCount(): int
    {
        return count($this->messages);
    }
}