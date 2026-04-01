<?php

declare(strict_types=1);

namespace SuperAgent\Context;

use Ramsey\Uuid\Uuid;

class Message
{
    public readonly string $id;
    public readonly string $timestamp;
    
    public function __construct(
        public readonly MessageRole $role,
        public readonly mixed $content,
        public readonly MessageType $type = MessageType::STANDARD,
        ?string $id = null,
        ?string $timestamp = null,
        public readonly array $metadata = [],
    ) {
        $this->id = $id ?? Uuid::uuid4()->toString();
        $this->timestamp = $timestamp ?? date('c');
    }
    
    /**
     * Create a user message
     */
    public static function user(string $content, array $metadata = []): self
    {
        return new self(
            role: MessageRole::USER,
            content: $content,
            metadata: $metadata,
        );
    }
    
    /**
     * Create an assistant message
     */
    public static function assistant(mixed $content, array $metadata = []): self
    {
        return new self(
            role: MessageRole::ASSISTANT,
            content: $content,
            metadata: $metadata,
        );
    }
    
    /**
     * Create a system message
     */
    public static function system(string $content, array $metadata = []): self
    {
        return new self(
            role: MessageRole::SYSTEM,
            content: $content,
            metadata: $metadata,
        );
    }
    
    /**
     * Create a boundary marker message
     */
    public static function boundary(string $content, array $metadata = []): self
    {
        return new self(
            role: MessageRole::SYSTEM,
            content: $content,
            type: MessageType::BOUNDARY,
            metadata: array_merge($metadata, ['is_boundary' => true]),
        );
    }
    
    /**
     * Create a summary message
     */
    public static function summary(string $content, array $metadata = []): self
    {
        return new self(
            role: MessageRole::SYSTEM,
            content: $content,
            type: MessageType::SUMMARY,
            metadata: array_merge($metadata, ['is_summary' => true]),
        );
    }
    
    /**
     * Check if this is a tool use message
     */
    public function isToolUse(): bool
    {
        if ($this->role !== MessageRole::ASSISTANT) {
            return false;
        }
        
        if (is_array($this->content)) {
            foreach ($this->content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'tool_use') {
                    return true;
                }
            }
        }
        
        return isset($this->metadata['tool_use']);
    }
    
    /**
     * Check if this is a tool result message
     */
    public function isToolResult(): bool
    {
        if ($this->role !== MessageRole::USER && $this->role !== MessageRole::ASSISTANT) {
            return false;
        }
        
        if (is_array($this->content)) {
            foreach ($this->content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'tool_result') {
                    return true;
                }
            }
        }
        
        return isset($this->metadata['tool_result']);
    }
    
    /**
     * Get the tool name if this is a tool message
     */
    public function getToolName(): ?string
    {
        if (is_array($this->content)) {
            foreach ($this->content as $part) {
                if (is_array($part)) {
                    if (($part['type'] ?? '') === 'tool_use') {
                        return $part['name'] ?? null;
                    }
                    if (($part['type'] ?? '') === 'tool_result') {
                        return $part['tool_use']['name'] ?? null;
                    }
                }
            }
        }
        
        return $this->metadata['tool_name'] ?? null;
    }
    
    /**
     * Clear the content of this message (for micro-compaction)
     */
    public function withClearedContent(string $placeholder = '[Content cleared for space]'): self
    {
        return new self(
            role: $this->role,
            content: $placeholder,
            type: $this->type,
            id: $this->id,
            timestamp: $this->timestamp,
            metadata: array_merge($this->metadata, ['content_cleared' => true]),
        );
    }
    
    /**
     * Convert to array for API submission
     */
    public function toArray(): array
    {
        $array = [
            'role' => $this->role->value,
            'content' => $this->content,
        ];
        
        if ($this->type !== MessageType::STANDARD) {
            $array['type'] = $this->type->value;
        }
        
        if (!empty($this->metadata)) {
            $array['metadata'] = $this->metadata;
        }
        
        return $array;
    }
}

enum MessageRole: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case SYSTEM = 'system';
}

enum MessageType: string
{
    case STANDARD = 'standard';
    case BOUNDARY = 'boundary';
    case SUMMARY = 'summary';
    case ATTACHMENT = 'attachment';
    case TOOL_USE = 'tool_use';
    case TOOL_RESULT = 'tool_result';
}