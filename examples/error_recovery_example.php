<?php

/**
 * Error Recovery Example
 * 
 * This example demonstrates how to use the error recovery mechanism
 * in SuperAgent to handle various types of errors automatically.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent\RecoverableAgent;
use SuperAgent\Providers\AnthropicProvider;

// Configure agent with error recovery
$config = [
    'provider' => [
        'type' => 'anthropic',
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-opus-20240229',
    ],
    
    // Error recovery configuration
    'error_recovery' => [
        'enabled' => true,
        'max_retries' => 3,
        
        // Custom retry strategies
        'retry_strategies' => [
            // Add custom patterns
            'custom_error' => [
                'pattern' => '/custom error pattern/i',
                'action' => 'retry',
                'backoff_type' => 'exponential',
                'base_delay' => 2000,
                'max_attempts' => 5,
            ],
        ],
        
        // Model fallback chain
        'fallback_models' => [
            'claude-3-opus-20240229' => 'claude-3-sonnet-20240229',
            'claude-3-sonnet-20240229' => 'claude-3-haiku-20240307',
        ],
        
        // Enable checkpointing
        'checkpoint_enabled' => true,
        'save_on_failure' => true,
    ],
];

// Create recoverable agent
$agent = new RecoverableAgent($config);

// Example 1: Basic usage with automatic recovery
echo "Example 1: Basic query with error recovery\n";
echo "==========================================\n";

try {
    $result = $agent->query("Analyze this code and provide suggestions");
    echo "Success: " . $result->content . "\n\n";
} catch (\Exception $e) {
    echo "Failed after all recovery attempts: " . $e->getMessage() . "\n\n";
}

// Example 2: Handle rate limits automatically
echo "Example 2: Rate limit handling\n";
echo "==============================\n";

// This will automatically wait and retry if rate limited
try {
    for ($i = 0; $i < 10; $i++) {
        $result = $agent->query("Quick query $i");
        echo "Query $i completed\n";
    }
} catch (\Exception $e) {
    echo "Rate limit handling failed: " . $e->getMessage() . "\n\n";
}

// Example 3: Token limit recovery with context compaction
echo "Example 3: Token limit recovery\n";
echo "===============================\n";

// Build up large context
$largeContext = str_repeat("This is a very long context. ", 1000);

try {
    $result = $agent->query($largeContext);
    echo "Successfully handled large context\n\n";
} catch (\Exception $e) {
    echo "Token limit recovery failed: " . $e->getMessage() . "\n\n";
}

// Example 4: Model fallback on overload
echo "Example 4: Model fallback\n";
echo "=========================\n";

// If Opus is overloaded, will automatically fall back to Sonnet
try {
    $result = $agent->query("Complex reasoning task");
    echo "Completed with model: " . $agent->getCurrentModel() . "\n\n";
} catch (\Exception $e) {
    echo "Model fallback failed: " . $e->getMessage() . "\n\n";
}

// Example 5: Custom error handling
echo "Example 5: Custom error handling\n";
echo "================================\n";

// Add custom error handler
$agent->onError(function ($error, $context) {
    echo "Custom handler: Error occurred - " . $error->getMessage() . "\n";
    echo "Retry attempt: " . ($context['attempt'] ?? 0) . "\n";
});

try {
    $result = $agent->query("Task that might fail");
    echo "Task completed successfully\n\n";
} catch (\Exception $e) {
    echo "Task failed: " . $e->getMessage() . "\n\n";
}

// Example 6: Recovery statistics
echo "Example 6: Recovery statistics\n";
echo "==============================\n";

$stats = $agent->getRecoveryStats();
echo "Recovery Statistics:\n";
echo "- Total attempts: " . $stats['total_attempts'] . "\n";
echo "- Successful recoveries: " . $stats['successful'] . "\n";
echo "- Failed recoveries: " . $stats['failed'] . "\n";
echo "- Average retry count: " . $stats['avg_retries'] . "\n";
echo "- Most common error: " . $stats['most_common_error'] . "\n\n";

// Example 7: Manual checkpoint management
echo "Example 7: Manual checkpoints\n";
echo "=============================\n";

// Create manual checkpoint
$checkpoint = $agent->createCheckpoint('before_risky_operation');

try {
    // Risky operation
    $result = $agent->query("Perform risky operation");
    echo "Risky operation succeeded\n";
} catch (\Exception $e) {
    // Restore from checkpoint
    $agent->restoreCheckpoint($checkpoint);
    echo "Restored from checkpoint after failure\n";
    
    // Try alternative approach
    $result = $agent->query("Perform safer alternative");
    echo "Alternative approach succeeded\n";
}

// Example 8: Progressive recovery actions
echo "\nExample 8: Progressive recovery\n";
echo "================================\n";

// Configure progressive recovery
$progressiveConfig = [
    'error_recovery' => [
        'retry_strategies' => [
            'progressive_error' => [
                'pattern' => '/complex error/i',
                'progressive' => true,
                'actions' => [
                    1 => 'retry',           // First attempt: simple retry
                    2 => 'compact_context', // Second: compact context
                    3 => 'downgrade_model', // Third: use simpler model
                    4 => 'split_task',      // Fourth: split into subtasks
                ],
            ],
        ],
    ],
];

$progressiveAgent = new RecoverableAgent($progressiveConfig);

try {
    $result = $progressiveAgent->query("Complex task that might fail multiple times");
    echo "Complex task completed with progressive recovery\n";
} catch (\Exception $e) {
    echo "Progressive recovery exhausted: " . $e->getMessage() . "\n";
}

// Example 9: Monitoring and alerting
echo "\nExample 9: Recovery monitoring\n";
echo "===============================\n";

// Set up monitoring
$agent->onRecoveryAttempt(function ($attempt, $error) {
    echo "Recovery attempt $attempt for: " . get_class($error) . "\n";
});

$agent->onRecoverySuccess(function ($attempt, $action) {
    echo "Recovery succeeded on attempt $attempt using: " . $action . "\n";
});

$agent->onRecoveryFailure(function ($error, $attempts) {
    echo "Recovery failed after $attempts attempts\n";
    // Send alert to monitoring system
    // AlertManager::send('critical', 'Agent recovery failed', ['error' => $error]);
});

// Test monitoring
try {
    $result = $agent->query("Task with monitoring");
    echo "Task completed with monitoring\n";
} catch (\Exception $e) {
    echo "Monitored task failed\n";
}

echo "\n=== Error Recovery Examples Complete ===\n";