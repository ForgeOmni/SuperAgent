<?php

declare(strict_types=1);

namespace SuperAgent\Context\Strategies;

use SuperAgent\Context\CompressionConfig;
use SuperAgent\Context\Message;
use SuperAgent\Context\MessageRole;
use SuperAgent\Context\TokenEstimator;
use SuperAgent\LLM\ProviderInterface;

class ConversationCompressor implements CompressionStrategy
{
    public function __construct(
        private TokenEstimator $tokenEstimator,
        private CompressionConfig $config,
        private ProviderInterface $provider,
    ) {}
    
    public function getPriority(): int
    {
        return 10; // Lower priority - use as fallback
    }
    
    public function getName(): string
    {
        return 'conversation_summary';
    }
    
    public function canCompress(array $messages, array $context = []): bool
    {
        // Need sufficient messages to summarize
        if (count($messages) < $this->config->minMessages) {
            return false;
        }
        
        // Need sufficient tokens to make it worthwhile
        $tokenCount = $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn($m) => $m->toArray(), $messages)
        );
        
        return $tokenCount >= $this->config->minTokens;
    }
    
    public function compress(array $messages, array $options = []): CompressionResult
    {
        $keepRecent = $options['keep_recent'] ?? $this->config->keepRecentMessages;
        $summaryPrompt = $options['summary_prompt'] ?? $this->getDefaultSummaryPrompt();
        
        // Calculate what to compress and what to keep
        $splitPoint = $this->calculateSplitPoint($messages, $keepRecent);
        $messagesToCompress = array_slice($messages, 0, $splitPoint);
        $messagesToKeep = array_slice($messages, $splitPoint);
        
        if (empty($messagesToCompress)) {
            return new CompressionResult(
                compressedMessages: [],
                preservedMessages: $messages,
                tokensSaved: 0,
            );
        }
        
        // Generate summary
        $summary = $this->generateSummary($messagesToCompress, $summaryPrompt);
        
        if ($summary === null) {
            // Summary generation failed
            return new CompressionResult(
                compressedMessages: [],
                preservedMessages: $messages,
                tokensSaved: 0,
                metadata: ['error' => 'Summary generation failed'],
            );
        }
        
        // Calculate token savings
        $originalTokens = $this->tokenEstimator->estimateMessagesTokens(
            array_map(fn($m) => $m->toArray(), $messagesToCompress)
        );
        $summaryTokens = $this->tokenEstimator->estimateTokens($summary);
        $tokensSaved = $originalTokens - $summaryTokens;
        
        // Create summary message
        $summaryMessage = Message::summary(
            content: $summary,
            metadata: [
                'messages_compressed' => count($messagesToCompress),
                'original_tokens' => $originalTokens,
                'summary_tokens' => $summaryTokens,
            ],
        );
        
        // Create boundary message
        $boundaryMessage = Message::boundary(
            content: $this->createBoundaryContent(
                count($messagesToCompress),
                $tokensSaved
            ),
            metadata: [
                'compact_type' => 'conversation_summary',
                'split_point' => $splitPoint,
            ],
        );
        
        return new CompressionResult(
            compressedMessages: [$summaryMessage],
            preservedMessages: $messagesToKeep,
            boundaryMessage: $boundaryMessage,
            tokensSaved: $tokensSaved,
            preCompactTokenCount: $this->tokenEstimator->estimateMessagesTokens(
                array_map(fn($m) => $m->toArray(), $messages)
            ),
            postCompactTokenCount: $this->tokenEstimator->estimateMessagesTokens(
                array_map(fn($m) => $m->toArray(), array_merge([$summaryMessage], $messagesToKeep))
            ),
            metadata: [
                'strategy' => 'conversation_summary',
                'messages_compressed' => count($messagesToCompress),
            ],
        );
    }
    
    /**
     * Generate a summary of the messages
     */
    private function generateSummary(array $messages, string $prompt): ?string
    {
        $conversation = $this->formatMessagesForSummary($messages);
        
        $systemPrompt = $prompt;
        $userPrompt = "Please summarize the following conversation:\n\n" . $conversation;
        
        try {
            $response = $this->provider->generateResponse(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                options: [
                    'max_tokens' => 4000,
                    'temperature' => 0.3,
                    'model' => $this->config->summaryModel,
                ],
            );
            
            return $response->content;
        } catch (\Exception $e) {
            // Log error
            return null;
        }
    }
    
    /**
     * Format messages for summary generation
     */
    private function formatMessagesForSummary(array $messages): string
    {
        $formatted = [];
        
        foreach ($messages as $message) {
            $role = ucfirst($message->role->value);
            $content = $this->formatContent($message->content);
            
            if ($message->isToolUse()) {
                $toolName = $message->getToolName();
                $formatted[] = "{$role}: [Tool Use: {$toolName}] {$content}";
            } elseif ($message->isToolResult()) {
                $toolName = $message->getToolName();
                $formatted[] = "{$role}: [Tool Result: {$toolName}] {$content}";
            } else {
                $formatted[] = "{$role}: {$content}";
            }
        }
        
        return implode("\n\n", $formatted);
    }
    
    /**
     * Format content for display
     */
    private function formatContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        
        if (!is_array($content)) {
            return (string) $content;
        }
        
        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part)) {
                if (isset($part['text'])) {
                    $parts[] = $part['text'];
                } elseif (isset($part['type'])) {
                    $parts[] = "[{$part['type']}]";
                }
            }
        }
        
        return implode(' ', $parts);
    }
    
    /**
     * Calculate the split point between compressed and preserved messages
     */
    private function calculateSplitPoint(array $messages, int $keepRecent): int
    {
        $total = count($messages);
        
        // Always keep at least keepRecent messages
        if ($total <= $keepRecent) {
            return 0; // Don't compress anything
        }
        
        // Find a good split point that preserves tool use/result pairs
        $splitPoint = $total - $keepRecent;
        
        // Adjust to preserve API invariants (tool_use followed by tool_result)
        $splitPoint = $this->adjustForToolPairs($messages, $splitPoint);
        
        return $splitPoint;
    }
    
    /**
     * Adjust split point to preserve tool use/result pairs
     */
    private function adjustForToolPairs(array $messages, int $splitPoint): int
    {
        if ($splitPoint <= 0 || $splitPoint >= count($messages)) {
            return $splitPoint;
        }
        
        // Check if we're splitting a tool pair
        $message = $messages[$splitPoint];
        
        // If this is a tool result, include the preceding tool use
        if ($message->isToolResult() && $splitPoint > 0) {
            $prevMessage = $messages[$splitPoint - 1];
            if ($prevMessage->isToolUse()) {
                return $splitPoint - 1;
            }
        }
        
        // If this is a tool use, include the following tool result
        if ($message->isToolUse() && $splitPoint < count($messages) - 1) {
            $nextMessage = $messages[$splitPoint + 1];
            if ($nextMessage->isToolResult()) {
                return $splitPoint + 2;
            }
        }
        
        return $splitPoint;
    }
    
    /**
     * Get the default summary prompt
     */
    private function getDefaultSummaryPrompt(): string
    {
        return <<<PROMPT
You are a conversation summarizer. Create a concise but comprehensive summary of the conversation.

Focus on:
1. Key decisions made
2. Important information discovered
3. Problems solved or errors encountered
4. Current state and context
5. Any pending tasks or questions

Keep the summary structured and easy to scan.
Preserve technical details and specific values when important.
Use bullet points for clarity.
PROMPT;
    }
    
    /**
     * Create boundary message content
     */
    private function createBoundaryContent(int $messagesCompressed, int $tokensSaved): string
    {
        return sprintf(
            "--- Conversation Summary ---\n" .
            "Compressed %d messages into a summary.\n" .
            "Tokens saved: %d\n" .
            "The summary above captures the key points of the compressed conversation.",
            $messagesCompressed,
            $tokensSaved
        );
    }
}