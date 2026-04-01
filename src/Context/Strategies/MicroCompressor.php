<?php

declare(strict_types=1);

namespace SuperAgent\Context\Strategies;

use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\Message;
use SuperAgent\Context\MessageRole;
use SuperAgent\Context\TokenEstimator;

class MicroCompressor implements CompressionStrategy
{
    public function __construct(
        private TokenEstimator $tokenEstimator,
        private CompressionConfig $config,
    ) {}
    
    public function getPriority(): int
    {
        return 1; // Highest priority - try this first
    }
    
    public function getName(): string
    {
        return 'micro_compact';
    }
    
    public function canCompress(array $messages, array $context = []): bool
    {
        if (!$this->config->enableMicroCompact) {
            return false;
        }
        
        // Need at least some messages to compress
        if (count($messages) < $this->config->minMessages) {
            return false;
        }
        
        // Check if there are compactable tool results
        $compactableCount = 0;
        foreach ($messages as $message) {
            if ($this->isCompactableMessage($message)) {
                $compactableCount++;
            }
        }
        
        return $compactableCount > 0;
    }
    
    public function compress(array $messages, array $options = []): CompressionResult
    {
        $keepRecent = $options['keep_recent'] ?? $this->config->keepRecentMessages;
        $minTokensToSave = $options['min_tokens_to_save'] ?? 1000;
        
        $originalTokenCount = $this->tokenEstimator->estimateMessagesTokens($messages);
        $processedMessages = [];
        $tokensSaved = 0;
        
        // Find the cutoff point for recent messages to preserve
        $totalMessages = count($messages);
        $cutoffIndex = max(0, $totalMessages - $keepRecent);
        
        foreach ($messages as $index => $message) {
            // Keep recent messages unchanged
            if ($index >= $cutoffIndex) {
                $processedMessages[] = $message;
                continue;
            }
            
            // Check if this message can be compacted
            if ($this->isCompactableMessage($message)) {
                $originalTokens = $this->tokenEstimator->estimateMessageTokens($message->toArray());
                $clearedMessage = $this->clearMessageContent($message);
                $newTokens = $this->tokenEstimator->estimateMessageTokens($clearedMessage->toArray());
                
                $tokensSaved += ($originalTokens - $newTokens);
                $processedMessages[] = $clearedMessage;
            } else {
                $processedMessages[] = $message;
            }
        }
        
        // Check if we saved enough tokens
        if ($tokensSaved < $minTokensToSave) {
            // Not worth compacting, return original
            return new CompressionResult(
                compressedMessages: [],
                preservedMessages: $messages,
                tokensSaved: 0,
                preCompactTokenCount: $originalTokenCount,
                postCompactTokenCount: $originalTokenCount,
            );
        }
        
        $newTokenCount = $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn($m) => $m->toArray(), $processedMessages)
        );
        
        // Create boundary message
        $boundaryMessage = Message::boundary(
            content: $this->createBoundaryContent($tokensSaved, count($messages)),
            metadata: [
                'compact_type' => 'micro',
                'tokens_saved' => $tokensSaved,
                'messages_cleared' => count(array_filter($processedMessages, fn($m) => 
                    isset($m->metadata['content_cleared']) && $m->metadata['content_cleared']
                )),
            ],
        );
        
        return new CompressionResult(
            compressedMessages: [],
            preservedMessages: $processedMessages,
            boundaryMessage: $boundaryMessage,
            tokensSaved: $tokensSaved,
            preCompactTokenCount: $originalTokenCount,
            postCompactTokenCount: $newTokenCount,
            metadata: [
                'strategy' => 'micro',
                'messages_cleared' => count(array_filter($processedMessages, fn($m) => 
                    isset($m->metadata['content_cleared']) && $m->metadata['content_cleared']
                )),
            ],
        );
    }
    
    /**
     * Check if a message is compactable
     */
    private function isCompactableMessage(Message $message): bool
    {
        // Only compact assistant messages with tool results
        if ($message->role !== MessageRole::ASSISTANT) {
            return false;
        }
        
        // Check if it's a tool result message
        if (!$message->isToolResult()) {
            return false;
        }
        
        // Check if the tool is in the compactable list
        $toolName = $message->getToolName();
        if ($toolName === null) {
            return false;
        }
        
        return $this->config->isCompactableTool($toolName);
    }
    
    /**
     * Clear the content of a message while preserving structure
     */
    private function clearMessageContent(Message $message): Message
    {
        $clearedContent = $this->clearContent($message->content);
        
        return new Message(
            role: $message->role,
            content: $clearedContent,
            type: $message->type,
            id: $message->id,
            timestamp: $message->timestamp,
            metadata: array_merge($message->metadata, [
                'content_cleared' => true,
                'original_tool' => $message->getToolName(),
            ]),
        );
    }
    
    /**
     * Clear content while preserving structure
     */
    private function clearContent(mixed $content): mixed
    {
        if (is_string($content)) {
            return '[Content cleared for space]';
        }
        
        if (!is_array($content)) {
            return $content;
        }
        
        $cleared = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                $cleared[] = $part;
                continue;
            }
            
            $type = $part['type'] ?? '';
            
            if ($type === 'tool_result') {
                // Clear tool result content
                $cleared[] = array_merge($part, [
                    'content' => '[Tool result cleared for space]',
                    'is_cleared' => true,
                ]);
            } elseif ($type === 'text' && strlen($part['text'] ?? '') > 1000) {
                // Clear long text content
                $cleared[] = array_merge($part, [
                    'text' => '[Long text cleared for space]',
                    'is_cleared' => true,
                ]);
            } else {
                $cleared[] = $part;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Create boundary message content
     */
    private function createBoundaryContent(int $tokensSaved, int $totalMessages): string
    {
        return sprintf(
            "--- Micro-compaction performed ---\n" .
            "Cleared old tool results to save %d tokens.\n" .
            "Total messages in context: %d\n" .
            "Recent messages preserved unchanged.",
            $tokensSaved,
            $totalMessages
        );
    }
}