<?php

namespace SuperAgent\ErrorRecovery;

class RecoveryAction
{
    public function __construct(
        public readonly string $type,
        public readonly array $params = []
    ) {}
    
    /**
     * Check if this action requires context modification
     */
    public function modifiesContext(): bool
    {
        return in_array($this->type, [
            'compact_context',
            'downgrade_model',
            'restore_checkpoint',
            'clear_cache',
        ]);
    }
    
    /**
     * Check if this action requires waiting
     */
    public function requiresWait(): bool
    {
        return in_array($this->type, [
            'retry_with_backoff',
            'rate_limit_wait',
        ]);
    }
    
    /**
     * Get human-readable description
     */
    public function getDescription(): string
    {
        return match ($this->type) {
            'retry' => 'Simple retry',
            'retry_with_backoff' => 'Retry with backoff',
            'compact_context' => 'Compact context and retry',
            'downgrade_model' => 'Switch to fallback model',
            'restore_checkpoint' => 'Restore from checkpoint',
            'clear_cache' => 'Clear cache and retry',
            'split_task' => 'Split into smaller tasks',
            'rate_limit_wait' => 'Wait for rate limit reset',
            default => 'Unknown action: ' . $this->type,
        };
    }
}