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
        $rawSummary = $this->generateSummary($messagesToCompress, $summaryPrompt);

        if ($rawSummary === null) {
            // Summary generation failed
            return new CompressionResult(
                compressedMessages: [],
                preservedMessages: $messages,
                tokensSaved: 0,
                metadata: ['error' => 'Summary generation failed'],
            );
        }

        // Format: strip <analysis> scratchpad, extract <summary>
        $summary = $this->formatCompactSummary($rawSummary);

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
     * Get the default summary prompt (9-section structured format from Claude Code).
     */
    private function getDefaultSummaryPrompt(): string
    {
        return <<<'PROMPT'
CRITICAL: Respond with TEXT ONLY. Do NOT call any tools.

Your task is to create a detailed summary of the conversation so far, paying close attention to the user's explicit requests and your previous actions.
This summary should be thorough in capturing technical details, code patterns, and architectural decisions that would be essential for continuing development work without losing context.

Before providing your final summary, wrap your analysis in <analysis> tags. In your analysis:

1. Chronologically analyze each message. For each section identify:
   - The user's explicit requests and intents
   - Your approach to addressing them
   - Key decisions, technical concepts and code patterns
   - Specific details: file names, code snippets, function signatures, file edits
   - Errors and how you fixed them
   - Specific user feedback, especially if they told you to do something differently.
2. Double-check for technical accuracy and completeness.

Your summary should include these sections:

1. Primary Request and Intent: Capture all user requests and intents in detail
2. Key Technical Concepts: List all important technical concepts, technologies, and frameworks
3. Files and Code Sections: Enumerate files examined, modified, or created with code snippets and why they matter
4. Errors and fixes: List all errors and fixes, including user feedback
5. Problem Solving: Document problems solved and ongoing troubleshooting
6. All user messages: List ALL non-tool-result user messages (critical for understanding feedback)
7. Pending Tasks: Outline pending tasks explicitly asked for
8. Current Work: Describe precisely what was being worked on immediately before this summary
9. Optional Next Step: The next step directly in line with the user's most recent explicit request

Output format:

<analysis>
[Your analysis]
</analysis>

<summary>
1. Primary Request and Intent: ...
2. Key Technical Concepts: ...
3. Files and Code Sections: ...
4. Errors and fixes: ...
5. Problem Solving: ...
6. All user messages: ...
7. Pending Tasks: ...
8. Current Work: ...
9. Optional Next Step: ...
</summary>

REMINDER: Do NOT call any tools. Respond with plain text only.
PROMPT;
    }

    /**
     * Format summary by stripping analysis scratchpad and extracting summary section.
     */
    private function formatCompactSummary(string $summary): string
    {
        // Strip analysis section (drafting scratchpad)
        $formatted = preg_replace('/<analysis>[\s\S]*?<\/analysis>/', '', $summary);

        // Extract summary section
        if (preg_match('/<summary>([\s\S]*?)<\/summary>/', $formatted, $matches)) {
            $content = trim($matches[1] ?? '');
            $formatted = preg_replace(
                '/<summary>[\s\S]*?<\/summary>/',
                "Summary:\n{$content}",
                $formatted,
            );
        }

        return trim(preg_replace('/\n\n+/', "\n\n", $formatted));
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