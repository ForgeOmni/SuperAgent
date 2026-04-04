<?php

namespace SuperAgent\ErrorRecovery;

class RetryStrategy
{
    private array $strategies;
    
    public function __construct(array $strategies = [])
    {
        $this->strategies = array_merge($this->getDefaultStrategies(), $strategies);
    }
    
    /**
     * Determine the recovery action based on error and attempt
     */
    public function determineAction(\Throwable $error, int $attempt): ?RecoveryAction
    {
        $errorClass = get_class($error);
        $errorMessage = $error->getMessage();
        
        // Check specific strategies first
        foreach ($this->strategies as $pattern => $strategy) {
            if ($this->matchesPattern($error, $pattern)) {
                return $this->createAction($strategy, $attempt);
            }
        }
        
        // Default strategy based on error type
        return $this->getDefaultAction($error, $attempt);
    }
    
    /**
     * Calculate wait time before retry
     */
    public function getWaitTime(\Throwable $error, int $attempt): int
    {
        $errorClass = get_class($error);
        
        // Check for rate limit errors
        if ($this->isRateLimitError($error)) {
            return $this->getRateLimitWaitTime($error, $attempt);
        }
        
        // Check configured strategies
        foreach ($this->strategies as $pattern => $strategy) {
            if ($this->matchesPattern($error, $pattern)) {
                return $this->calculateWaitTime($strategy, $attempt);
            }
        }
        
        // Default exponential backoff
        return $this->exponentialBackoff($attempt);
    }
    
    /**
     * Check if error matches pattern
     */
    private function matchesPattern(\Throwable $error, string $pattern): bool
    {
        // Check class name
        if (class_exists($pattern) && $error instanceof $pattern) {
            return true;
        }
        
        // Check message pattern
        if (str_starts_with($pattern, '/') && str_ends_with($pattern, '/')) {
            return preg_match($pattern, $error->getMessage());
        }
        
        // Check exact message match
        return str_contains($error->getMessage(), $pattern);
    }
    
    /**
     * Create recovery action from strategy
     */
    private function createAction(array $strategy, int $attempt): ?RecoveryAction
    {
        // Check max attempts for this strategy
        $maxAttempts = $strategy['max_attempts'] ?? 3;
        if ($attempt > $maxAttempts) {
            return null;
        }
        
        // Determine action type
        $actionType = $strategy['action'] ?? 'retry';
        
        // Progressive actions based on attempt number
        if (isset($strategy['progressive']) && $strategy['progressive']) {
            $actionType = $this->getProgressiveAction($attempt);
        }
        
        return new RecoveryAction(
            type: $actionType,
            params: $strategy['params'] ?? []
        );
    }
    
    /**
     * Get progressive action based on attempt
     */
    private function getProgressiveAction(int $attempt): string
    {
        return match ($attempt) {
            1 => 'retry',
            2 => 'compact_context',
            3 => 'downgrade_model',
            4 => 'restore_checkpoint',
            default => 'split_task',
        };
    }
    
    /**
     * Calculate wait time from strategy
     */
    private function calculateWaitTime(array $strategy, int $attempt): int
    {
        $type = $strategy['backoff_type'] ?? 'exponential';
        $baseDelay = $strategy['base_delay'] ?? 1000;
        $maxDelay = $strategy['max_delay'] ?? 30000;
        
        $delay = match ($type) {
            'none' => 0,
            'fixed' => $baseDelay,
            'linear' => $baseDelay * $attempt,
            'exponential' => $baseDelay * pow(2, $attempt - 1),
            'fibonacci' => $this->fibonacci($attempt) * $baseDelay,
            'random' => rand($baseDelay, $maxDelay),
            default => $this->exponentialBackoff($attempt, $baseDelay),
        };
        
        // Apply jitter if configured
        if ($strategy['jitter'] ?? false) {
            $jitterAmount = $delay * 0.1; // 10% jitter
            $delay += rand(-$jitterAmount, $jitterAmount);
        }
        
        return min($delay, $maxDelay);
    }
    
    /**
     * Check if this is a rate limit error
     */
    private function isRateLimitError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'rate limit') ||
               str_contains($message, 'too many requests') ||
               str_contains($message, '429');
    }
    
    /**
     * Get wait time for rate limit errors
     */
    private function getRateLimitWaitTime(\Throwable $error, int $attempt): int
    {
        $message = $error->getMessage();
        
        // Try to extract retry-after from message
        if (preg_match('/retry.{0,10}after.{0,10}(\d+)/i', $message, $matches)) {
            return (int)$matches[1] * 1000; // Convert to milliseconds
        }
        
        // Progressive backoff for rate limits
        return match ($attempt) {
            1 => 5000,   // 5 seconds
            2 => 15000,  // 15 seconds
            3 => 30000,  // 30 seconds
            default => 60000, // 60 seconds
        };
    }
    
    /**
     * Get default action based on error type
     */
    private function getDefaultAction(\Throwable $error, int $attempt): ?RecoveryAction
    {
        if ($attempt > 3) {
            return null; // Give up after 3 attempts by default
        }
        
        // Token limit errors
        if (str_contains($error->getMessage(), 'token') || 
            str_contains($error->getMessage(), 'context')) {
            return new RecoveryAction('compact_context');
        }
        
        // Model errors
        if (str_contains($error->getMessage(), 'model') || 
            str_contains($error->getMessage(), 'overloaded')) {
            return new RecoveryAction('downgrade_model');
        }
        
        // Default retry
        return new RecoveryAction('retry');
    }
    
    /**
     * Calculate exponential backoff
     */
    private function exponentialBackoff(int $attempt, int $baseDelay = 1000): int
    {
        return min($baseDelay * pow(2, $attempt - 1), 30000);
    }
    
    /**
     * Calculate fibonacci number
     */
    private function fibonacci(int $n): int
    {
        if ($n <= 1) return $n;
        return $this->fibonacci($n - 1) + $this->fibonacci($n - 2);
    }
    
    /**
     * Get default strategies
     */
    private function getDefaultStrategies(): array
    {
        return [
            // Rate limit errors
            '/rate limit/i' => [
                'action' => 'retry_with_backoff',
                'backoff_type' => 'exponential',
                'base_delay' => 5000,
                'max_delay' => 60000,
                'max_attempts' => 5,
            ],
            
            // Network errors
            '/connection|timeout/i' => [
                'action' => 'retry',
                'backoff_type' => 'exponential',
                'base_delay' => 1000,
                'max_delay' => 10000,
                'max_attempts' => 3,
                'jitter' => true,
            ],
            
            // Token/context errors
            '/token.*limit|context.*too.*large/i' => [
                'action' => 'compact_context',
                'progressive' => true,
                'max_attempts' => 2,
            ],
            
            // Model overload
            '/overloaded|unavailable/i' => [
                'action' => 'downgrade_model',
                'backoff_type' => 'fixed',
                'base_delay' => 3000,
                'max_attempts' => 2,
            ],
            
            // Generic temporary errors
            '/temporary|transient/i' => [
                'action' => 'retry',
                'backoff_type' => 'linear',
                'base_delay' => 2000,
                'max_attempts' => 3,
            ],
        ];
    }
}