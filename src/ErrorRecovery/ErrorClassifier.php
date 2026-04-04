<?php

namespace SuperAgent\ErrorRecovery;

use SuperAgent\Exceptions\RateLimitException;
use SuperAgent\Exceptions\TokenLimitException;
use SuperAgent\Exceptions\NetworkException;
use SuperAgent\Exceptions\ModelOverloadedException;

class ErrorClassifier
{
    /**
     * Classify error and return specific exception type
     */
    public function classify(\Throwable $error): \Throwable
    {
        $message = strtolower($error->getMessage());
        $code = $error->getCode();
        
        // Rate limit errors
        if ($this->isRateLimitError($message, $code)) {
            return new RateLimitException(
                $error->getMessage(),
                $code,
                $error
            );
        }
        
        // Token/context limit errors
        if ($this->isTokenLimitError($message)) {
            return new TokenLimitException(
                $error->getMessage(),
                $code,
                $error
            );
        }
        
        // Network errors
        if ($this->isNetworkError($message, $code)) {
            return new NetworkException(
                $error->getMessage(),
                $code,
                $error
            );
        }
        
        // Model overloaded errors
        if ($this->isModelOverloadedError($message)) {
            return new ModelOverloadedException(
                $error->getMessage(),
                $code,
                $error
            );
        }
        
        // Return original error if not classified
        return $error;
    }
    
    /**
     * Get recovery priority for error type
     */
    public function getPriority(\Throwable $error): int
    {
        return match (true) {
            $error instanceof RateLimitException => 1,        // Highest priority - wait and retry
            $error instanceof NetworkException => 2,          // High - immediate retry
            $error instanceof TokenLimitException => 3,       // Medium - needs context adjustment
            $error instanceof ModelOverloadedException => 4,  // Low - needs model change
            default => 5,                                     // Lowest - generic handling
        };
    }
    
    /**
     * Get suggested recovery strategy
     */
    public function getSuggestedStrategy(\Throwable $error): array
    {
        return match (true) {
            $error instanceof RateLimitException => [
                'strategy' => 'exponential_backoff',
                'initial_wait' => $this->extractWaitTime($error->getMessage()) ?? 5000,
                'max_attempts' => 5,
            ],
            
            $error instanceof NetworkException => [
                'strategy' => 'immediate_retry',
                'with_jitter' => true,
                'max_attempts' => 3,
            ],
            
            $error instanceof TokenLimitException => [
                'strategy' => 'progressive',
                'actions' => ['compact_context', 'split_task'],
                'max_attempts' => 2,
            ],
            
            $error instanceof ModelOverloadedException => [
                'strategy' => 'fallback',
                'action' => 'downgrade_model',
                'wait_time' => 3000,
            ],
            
            default => [
                'strategy' => 'default',
                'max_attempts' => 3,
            ],
        };
    }
    
    /**
     * Check if error is rate limit
     */
    private function isRateLimitError(string $message, int $code): bool
    {
        return $code === 429 ||
               str_contains($message, 'rate limit') ||
               str_contains($message, 'too many requests') ||
               str_contains($message, 'quota exceeded') ||
               str_contains($message, 'throttled');
    }
    
    /**
     * Check if error is token limit
     */
    private function isTokenLimitError(string $message): bool
    {
        return str_contains($message, 'token') && (
               str_contains($message, 'limit') ||
               str_contains($message, 'exceeded') ||
               str_contains($message, 'too large') ||
               str_contains($message, 'too long')
        ) ||
        str_contains($message, 'context') && (
            str_contains($message, 'too large') ||
            str_contains($message, 'exceeded')
        );
    }
    
    /**
     * Check if error is network related
     */
    private function isNetworkError(string $message, int $code): bool
    {
        return $code >= 500 ||
               str_contains($message, 'connection') ||
               str_contains($message, 'timeout') ||
               str_contains($message, 'network') ||
               str_contains($message, 'dns') ||
               str_contains($message, 'socket') ||
               str_contains($message, 'curl');
    }
    
    /**
     * Check if model is overloaded
     */
    private function isModelOverloadedError(string $message): bool
    {
        return str_contains($message, 'overloaded') ||
               str_contains($message, 'unavailable') ||
               str_contains($message, 'capacity') ||
               str_contains($message, 'busy') ||
               str_contains($message, 'model') && str_contains($message, 'not available');
    }
    
    /**
     * Extract wait time from error message
     */
    private function extractWaitTime(string $message): ?int
    {
        // Look for patterns like "retry after 30 seconds"
        if (preg_match('/retry\s*after\s*(\d+)\s*(second|seconds|minute|minutes|hour|hours)?/i', $message, $matches)) {
            $value = (int)$matches[1];
            $unit = isset($matches[2]) ? strtolower($matches[2]) : 'seconds';
            
            return match (true) {
                str_starts_with($unit, 'minute') => $value * 60 * 1000,
                str_starts_with($unit, 'hour') => $value * 3600 * 1000,
                default => $value * 1000, // seconds
            };
        }
        
        // Look for "X-RateLimit-Reset" style timestamps
        if (preg_match('/reset.{0,10}(\d{10})/i', $message, $matches)) {
            $resetTime = (int)$matches[1];
            $waitTime = max(0, $resetTime - time());
            return $waitTime * 1000; // Convert to milliseconds
        }
        
        return null;
    }
}