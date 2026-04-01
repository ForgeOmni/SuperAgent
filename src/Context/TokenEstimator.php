<?php

declare(strict_types=1);

namespace SuperAgent\Context;

class TokenEstimator
{
    /**
     * Rough estimation: ~4 characters per token
     * This is a reasonable approximation for most languages
     */
    private const CHARS_PER_TOKEN = 4;
    
    /**
     * Model context windows (in tokens)
     */
    private const MODEL_CONTEXT_WINDOWS = [
        // Anthropic models
        'claude-3-opus' => 200_000,
        'claude-3-sonnet' => 200_000,
        'claude-3-haiku' => 200_000,
        'claude-3-5-sonnet' => 200_000,
        'claude-3-5-haiku' => 200_000,
        'claude-4-opus' => 1_000_000,
        'claude-4-sonnet' => 1_000_000,
        
        // OpenAI models
        'gpt-4' => 8_192,
        'gpt-4-32k' => 32_768,
        'gpt-4-turbo-preview' => 128_000,
        'gpt-4-turbo' => 128_000,
        'gpt-4o' => 128_000,
        'gpt-3.5-turbo' => 16_385,
        
        // Default fallback
        'default' => 200_000,
    ];
    
    /**
     * Compaction buffer tokens
     */
    private const AUTOCOMPACT_BUFFER_TOKENS = 13_000;
    private const WARNING_THRESHOLD_BUFFER_TOKENS = 20_000;
    private const COMPACT_MAX_OUTPUT_TOKENS = 20_000;
    
    /**
     * Estimate token count for a string
     */
    public function estimateTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }
        
        // Basic character-based estimation
        $charCount = mb_strlen($text);
        $tokenEstimate = (int) ceil($charCount / self::CHARS_PER_TOKEN);
        
        // Add overhead for special characters and formatting
        $specialChars = substr_count($text, "\n") + substr_count($text, "\t");
        $tokenEstimate += (int) ceil($specialChars * 0.5);
        
        return $tokenEstimate;
    }
    
    /**
     * Estimate tokens for an array of messages
     */
    public function estimateMessagesTokens(array $messages): int
    {
        $totalTokens = 0;
        
        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $totalTokens += $this->estimateMessageTokens($message->toArray());
            } else {
                $totalTokens += $this->estimateMessageTokens($message);
            }
        }
        
        // Add overhead for message structure
        $totalTokens += count($messages) * 4; // ~4 tokens per message for metadata
        
        return $totalTokens;
    }
    
    /**
     * Estimate tokens for a single message
     */
    public function estimateMessageTokens(array|Message $message): int
    {
        if ($message instanceof Message) {
            $message = $message->toArray();
        }
        
        $tokens = 0;
        
        // Role token (user/assistant/system)
        $tokens += 1;
        
        // Content estimation
        if (isset($message['content'])) {
            if (is_string($message['content'])) {
                $tokens += $this->estimateTokens($message['content']);
            } elseif (is_array($message['content'])) {
                foreach ($message['content'] as $part) {
                    if (is_string($part)) {
                        $tokens += $this->estimateTokens($part);
                    } elseif (is_array($part)) {
                        $tokens += $this->estimateContentPartTokens($part);
                    }
                }
            }
        }
        
        // Tool use estimation
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCall) {
                $tokens += $this->estimateToolCallTokens($toolCall);
            }
        }
        
        if (isset($message['tool_use'])) {
            $tokens += $this->estimateToolUseTokens($message['tool_use']);
        }
        
        return $tokens;
    }
    
    /**
     * Estimate tokens for a content part (text, image, etc.)
     */
    private function estimateContentPartTokens(array $part): int
    {
        $type = $part['type'] ?? 'text';
        
        return match ($type) {
            'text' => $this->estimateTokens($part['text'] ?? ''),
            'image' => $this->estimateImageTokens($part),
            'tool_use' => $this->estimateToolUseTokens($part),
            'tool_result' => $this->estimateToolResultTokens($part),
            default => 10, // Default overhead for unknown types
        };
    }
    
    /**
     * Estimate tokens for an image
     * Images typically use ~1,000-3,000 tokens depending on size
     */
    private function estimateImageTokens(array $image): int
    {
        // Base cost for image
        $tokens = 1_000;
        
        // Add more if high resolution
        if (isset($image['detail']) && $image['detail'] === 'high') {
            $tokens = 3_000;
        }
        
        return $tokens;
    }
    
    /**
     * Estimate tokens for tool use
     */
    private function estimateToolUseTokens(array $toolUse): int
    {
        $tokens = 5; // Base overhead
        
        if (isset($toolUse['name'])) {
            $tokens += (int) ceil(strlen($toolUse['name']) / 4);
        }
        
        if (isset($toolUse['input'])) {
            $inputJson = json_encode($toolUse['input']);
            $tokens += $this->estimateTokens($inputJson);
        }
        
        return $tokens;
    }
    
    /**
     * Estimate tokens for tool result
     */
    private function estimateToolResultTokens(array $toolResult): int
    {
        $tokens = 5; // Base overhead
        
        if (isset($toolResult['content'])) {
            if (is_string($toolResult['content'])) {
                $tokens += $this->estimateTokens($toolResult['content']);
            } else {
                $tokens += $this->estimateTokens(json_encode($toolResult['content']));
            }
        }
        
        return $tokens;
    }
    
    /**
     * Estimate tokens for tool call
     */
    private function estimateToolCallTokens(array $toolCall): int
    {
        $tokens = 5; // Base overhead
        
        if (isset($toolCall['function']['name'])) {
            $tokens += (int) ceil(strlen($toolCall['function']['name']) / 4);
        }
        
        if (isset($toolCall['function']['arguments'])) {
            $tokens += $this->estimateTokens($toolCall['function']['arguments']);
        }
        
        return $tokens;
    }
    
    /**
     * Get the context window size for a model
     */
    public function getContextWindow(string $model): int
    {
        // Check environment override
        $override = $_ENV['CLAUDE_CODE_AUTO_COMPACT_WINDOW'] ?? null;
        if ($override !== null) {
            return (int) $override;
        }
        
        // Direct match first
        if (isset(self::MODEL_CONTEXT_WINDOWS[$model])) {
            return self::MODEL_CONTEXT_WINDOWS[$model];
        }
        
        // Normalize model name for pattern matching
        $modelLower = strtolower($model);
        
        // Check for more specific patterns first (longer patterns have priority)
        $patterns = self::MODEL_CONTEXT_WINDOWS;
        uksort($patterns, fn($a, $b) => strlen($b) <=> strlen($a));
        
        foreach ($patterns as $pattern => $window) {
            if ($pattern === 'default') continue;
            if (str_contains($modelLower, strtolower($pattern))) {
                return $window;
            }
        }
        
        return self::MODEL_CONTEXT_WINDOWS['default'];
    }
    
    /**
     * Get the effective context window size (accounting for output tokens)
     */
    public function getEffectiveContextWindow(string $model): int
    {
        $contextWindow = $this->getContextWindow($model);
        
        // Reserve space for output
        return $contextWindow - self::COMPACT_MAX_OUTPUT_TOKENS;
    }
    
    /**
     * Get the auto-compact threshold for a model
     */
    public function getAutoCompactThreshold(string $model): int
    {
        $effectiveWindow = $this->getEffectiveContextWindow($model);
        
        // Check for percentage override
        $pctOverride = $_ENV['CLAUDE_AUTOCOMPACT_PCT_OVERRIDE'] ?? null;
        if ($pctOverride !== null) {
            $percentage = (float) $pctOverride / 100;
            return (int) ($effectiveWindow * $percentage);
        }
        
        return $effectiveWindow - self::AUTOCOMPACT_BUFFER_TOKENS;
    }
    
    /**
     * Get the warning threshold for a model
     */
    public function getWarningThreshold(string $model): int
    {
        $effectiveWindow = $this->getEffectiveContextWindow($model);
        return $effectiveWindow - self::WARNING_THRESHOLD_BUFFER_TOKENS;
    }
    
    /**
     * Check if messages should trigger auto-compact
     */
    public function shouldAutoCompact(array $messages, string $model): bool
    {
        if ($_ENV['DISABLE_COMPACT'] ?? false) {
            return false;
        }
        
        if ($_ENV['DISABLE_AUTO_COMPACT'] ?? false) {
            return false;
        }
        
        $tokenCount = $this->estimateMessagesTokens($messages);
        $threshold = $this->getAutoCompactThreshold($model);
        
        return $tokenCount >= $threshold;
    }
    
    /**
     * Check if messages are approaching the warning threshold
     */
    public function isApproachingLimit(array $messages, string $model): bool
    {
        $tokenCount = $this->estimateMessagesTokens($messages);
        $threshold = $this->getWarningThreshold($model);
        
        return $tokenCount >= $threshold;
    }
    
    /**
     * Calculate how many tokens can be saved by removing old tool results
     */
    public function calculatePotentialSavings(array $messages, array $compactableTools): int
    {
        $savings = 0;
        
        foreach ($messages as $message) {
            if (!$this->isCompactableToolResult($message, $compactableTools)) {
                continue;
            }
            
            // Estimate current size
            $currentSize = $this->estimateMessageTokens($message);
            
            // Estimate size after clearing content
            $clearedMessage = $message;
            if (isset($clearedMessage['content'])) {
                $clearedMessage['content'] = '[Content cleared for space]';
            }
            $clearedSize = $this->estimateMessageTokens($clearedMessage);
            
            $savings += ($currentSize - $clearedSize);
        }
        
        return $savings;
    }
    
    /**
     * Check if a message is a compactable tool result
     */
    private function isCompactableToolResult(array $message, array $compactableTools): bool
    {
        if (($message['role'] ?? '') !== 'assistant') {
            return false;
        }
        
        if (!isset($message['content']) || !is_array($message['content'])) {
            return false;
        }
        
        foreach ($message['content'] as $part) {
            if (!is_array($part)) {
                continue;
            }
            
            if (($part['type'] ?? '') === 'tool_result') {
                $toolName = $part['tool_use']['name'] ?? '';
                if (in_array($toolName, $compactableTools, true)) {
                    return true;
                }
            }
        }
        
        return false;
    }
}