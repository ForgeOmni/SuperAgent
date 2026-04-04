<?php

return [
    /**
     * Error Recovery Configuration
     */
    
    // Enable error recovery globally
    'enabled' => env('SUPERAGENT_ERROR_RECOVERY_ENABLED', true),
    
    // Maximum retry attempts
    'max_retries' => env('SUPERAGENT_ERROR_RECOVERY_MAX_RETRIES', 3),
    
    // Default recoverable behavior for unclassified errors
    'default_recoverable' => true,
    
    // Enable checkpoint creation
    'checkpoint_enabled' => true,
    
    // Maximum checkpoints to keep in memory
    'max_checkpoints' => 5,
    
    // Save checkpoint to disk on failure
    'save_on_failure' => true,
    
    // Checkpoint storage path
    'checkpoint_path' => storage_path('superagent/recovery'),
    
    /**
     * Error Classification
     */
    
    // Errors that are never recoverable
    'unrecoverable_errors' => [
        \InvalidArgumentException::class,
        \LogicException::class,
        \TypeError::class,
        \ParseError::class,
    ],
    
    // Patterns that indicate recoverable errors
    'recoverable_patterns' => [
        '/rate limit/i',
        '/timeout/i',
        '/connection/i',
        '/temporary/i',
        '/overloaded/i',
        '/try again/i',
        '/503 service unavailable/i',
        '/502 bad gateway/i',
    ],
    
    /**
     * Retry Strategies
     */
    'retry_strategies' => [
        // Rate limit errors - exponential backoff with longer waits
        'rate_limit' => [
            'pattern' => '/rate limit|too many requests|429/i',
            'action' => 'retry_with_backoff',
            'backoff_type' => 'exponential',
            'base_delay' => 5000, // 5 seconds
            'max_delay' => 60000, // 60 seconds
            'max_attempts' => 5,
            'jitter' => true,
        ],
        
        // Network errors - quick retry with jitter
        'network' => [
            'pattern' => '/connection|timeout|network/i',
            'action' => 'retry',
            'backoff_type' => 'exponential',
            'base_delay' => 1000, // 1 second
            'max_delay' => 10000, // 10 seconds
            'max_attempts' => 3,
            'jitter' => true,
        ],
        
        // Token limit errors - compact and retry
        'token_limit' => [
            'pattern' => '/token.*limit|context.*too.*large/i',
            'action' => 'compact_context',
            'progressive' => true,
            'max_attempts' => 2,
        ],
        
        // Model overload - downgrade and retry
        'model_overload' => [
            'pattern' => '/overloaded|unavailable|capacity/i',
            'action' => 'downgrade_model',
            'backoff_type' => 'fixed',
            'base_delay' => 3000, // 3 seconds
            'max_attempts' => 2,
        ],
    ],
    
    /**
     * Model Fallback Configuration
     */
    'fallback_models' => [
        // Anthropic models
        'claude-3-opus-20240229' => 'claude-3-sonnet-20240229',
        'claude-3-sonnet-20240229' => 'claude-3-haiku-20240307',
        'claude-3-haiku-20240307' => null, // No fallback
        
        // OpenAI models
        'gpt-4-turbo-preview' => 'gpt-4',
        'gpt-4' => 'gpt-3.5-turbo',
        'gpt-3.5-turbo' => null, // No fallback
        
        // Add more model fallbacks as needed
    ],
    
    /**
     * Recovery Actions
     */
    'recovery_actions' => [
        // Actions that modify context
        'context_modifying' => [
            'compact_context',
            'downgrade_model',
            'restore_checkpoint',
            'clear_cache',
        ],
        
        // Actions that require waiting
        'wait_required' => [
            'retry_with_backoff',
            'rate_limit_wait',
        ],
    ],
    
    /**
     * Monitoring and Alerting
     */
    'monitoring' => [
        // Alert after N consecutive failures
        'alert_threshold' => 10,
        
        // Log all recovery attempts
        'log_attempts' => true,
        
        // Log successful recoveries
        'log_successes' => true,
        
        // Metrics collection
        'collect_metrics' => true,
    ],
];