<?php

namespace SuperAgent\ErrorRecovery;

use SuperAgent\Contracts\LLMProvider;
use SuperAgent\Exceptions\RecoverableException;
use SuperAgent\Exceptions\UnrecoverableException;
use SuperAgent\Telemetry\EventDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ErrorRecoveryManager
{
    private array $config;
    private LoggerInterface $logger;
    private ?object $events = null;
    private array $retryHistory = [];
    private array $checkpoints = [];

    public function __construct(array $config = [], ?EventDispatcher $events = null)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = $config['logger'] ?? new NullLogger();

        // Use injected EventDispatcher or fall back to singleton
        if ($events !== null) {
            $this->events = $events;
        } elseif (class_exists('\SuperAgent\Telemetry\EventDispatcher')) {
            try {
                $this->events = EventDispatcher::getInstance();
            } catch (\Exception $e) {
                // EventDispatcher not available in test environment
                $this->events = null;
            }
        }
    }
    
    /**
     * Execute a callable with automatic error recovery
     */
    public function execute(callable $operation, array $context = []): mixed
    {
        $attempts = 0;
        $lastException = null;
        $strategy = new RetryStrategy($this->config['retry_strategies']);
        
        while ($attempts < $this->config['max_retries']) {
            try {
                $attempts++;
                
                // Emit event if available
                if ($this->events) {
                    $this->events->dispatch('error_recovery.attempt', [
                        'attempt' => $attempts,
                        'context' => $context,
                    ]);
                }
                
                // Create checkpoint before execution
                if ($this->config['checkpoint_enabled'] && $attempts === 1) {
                    $this->createCheckpoint($context);
                }
                
                // Execute the operation
                $result = $operation();
                
                // Success - clear retry history for this context
                $this->clearRetryHistory($context);
                
                return $result;
                
            } catch (\Throwable $e) {
                $lastException = $e;
                
                // Log the error
                $this->logger->warning('Operation failed, attempting recovery', [
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
                
                // Check if error is recoverable
                if (!$this->isRecoverable($e)) {
                    $this->logger->error('Unrecoverable error encountered', [
                        'error' => $e->getMessage(),
                    ]);
                    throw new UnrecoverableException(
                        "Unrecoverable error: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
                
                // Record retry history
                $this->recordRetry($context, $e, $attempts);
                
                // Apply recovery strategy
                $recoveryAction = $strategy->determineAction($e, $attempts);
                
                if ($recoveryAction === null) {
                    break; // No more retries
                }
                
                // Execute recovery action
                $this->executeRecoveryAction($recoveryAction, $context);
                
                // Wait before retry
                if ($attempts < $this->config['max_retries']) {
                    $waitTime = $strategy->getWaitTime($e, $attempts);
                    if ($waitTime > 0) {
                        $this->logger->info("Waiting {$waitTime}ms before retry");
                        usleep($waitTime * 1000);
                    }
                }
            }
        }
        
        // All retries exhausted
        $this->handleExhaustedRetries($lastException, $context);
        
        throw new RecoverableException(
            "All retry attempts exhausted",
            0,
            $lastException
        );
    }
    
    /**
     * Determine if an error is recoverable
     */
    private function isRecoverable(\Throwable $e): bool
    {
        // Check unrecoverable list
        foreach ($this->config['unrecoverable_errors'] as $errorClass) {
            if ($e instanceof $errorClass) {
                return false;
            }
        }
        
        // Check recoverable patterns
        $message = $e->getMessage();
        foreach ($this->config['recoverable_patterns'] as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        
        // Default behavior based on error type
        return match (true) {
            $e instanceof \RuntimeException => true,
            $e instanceof \InvalidArgumentException => false,
            $e instanceof \LogicException => false,
            str_contains($message, 'rate limit') => true,
            str_contains($message, 'timeout') => true,
            str_contains($message, 'connection') => true,
            str_contains($message, 'temporary') => true,
            default => $this->config['default_recoverable'],
        };
    }
    
    /**
     * Execute recovery action
     */
    private function executeRecoveryAction(RecoveryAction $action, array &$context): void
    {
        switch ($action->type) {
            case 'retry':
                // Simple retry, no action needed
                break;
                
            case 'retry_with_backoff':
                // Backoff is handled by the strategy
                break;
                
            case 'compact_context':
                if (isset($context['agent'])) {
                    $this->logger->info('Compacting context before retry');
                    $context['agent']->compactContext();
                }
                break;
                
            case 'downgrade_model':
                if (isset($context['provider'])) {
                    $newModel = $action->params['model'] ?? $this->getFallbackModel($context['provider']);
                    $this->logger->info("Downgrading model to: {$newModel}");
                    $context['provider']->setModel($newModel);
                }
                break;
                
            case 'restore_checkpoint':
                $this->restoreCheckpoint($context);
                break;
                
            case 'clear_cache':
                // Clear any cached data that might be corrupted
                if (isset($context['cache'])) {
                    $context['cache']->clear();
                }
                break;
                
            case 'split_task':
                // This would need task-specific implementation
                $this->logger->info('Task splitting requested - manual intervention may be needed');
                break;
        }
        
        // Emit recovery event if available
        if ($this->events) {
            $this->events->dispatch('error_recovery.action', [
                'action' => $action->type,
                'params' => $action->params,
            ]);
        }
    }
    
    /**
     * Create a checkpoint for potential recovery
     */
    private function createCheckpoint(array $context): void
    {
        $checkpoint = [
            'timestamp' => microtime(true),
            'context' => $context,
            'state' => $this->captureState($context),
        ];
        
        $this->checkpoints[] = $checkpoint;
        
        // Keep only recent checkpoints
        if (count($this->checkpoints) > $this->config['max_checkpoints']) {
            array_shift($this->checkpoints);
        }
    }
    
    /**
     * Restore from checkpoint
     */
    private function restoreCheckpoint(array &$context): void
    {
        if (empty($this->checkpoints)) {
            $this->logger->warning('No checkpoints available for restoration');
            return;
        }
        
        $checkpoint = end($this->checkpoints);
        $this->logger->info('Restoring from checkpoint', [
            'timestamp' => $checkpoint['timestamp'],
        ]);
        
        // Restore context
        foreach ($checkpoint['context'] as $key => $value) {
            $context[$key] = $value;
        }
        
        // Restore state
        $this->restoreState($context, $checkpoint['state']);
    }
    
    /**
     * Capture current state
     */
    private function captureState(array $context): array
    {
        $state = [];
        
        if (isset($context['agent'])) {
            $state['messages'] = $context['agent']->getMessages();
            $state['turn_count'] = $context['agent']->getTurnCount();
        }
        
        if (isset($context['provider'])) {
            $state['model'] = $context['provider']->getModel();
        }
        
        return $state;
    }
    
    /**
     * Restore state
     */
    private function restoreState(array &$context, array $state): void
    {
        if (isset($context['agent']) && isset($state['messages'])) {
            $context['agent']->setMessages($state['messages']);
            $context['agent']->setTurnCount($state['turn_count'] ?? 0);
        }
        
        if (isset($context['provider']) && isset($state['model'])) {
            $context['provider']->setModel($state['model']);
        }
    }
    
    /**
     * Get fallback model for provider
     */
    private function getFallbackModel(LLMProvider $provider): string
    {
        $currentModel = $provider->getModel();
        
        // Check configured fallbacks
        if (isset($this->config['fallback_models'][$currentModel])) {
            return $this->config['fallback_models'][$currentModel];
        }
        
        // Default fallback chains
        $fallbackChains = [
            'claude-3-opus-20240229' => 'claude-3-sonnet-20240229',
            'claude-3-sonnet-20240229' => 'claude-3-haiku-20240307',
            'gpt-4-turbo-preview' => 'gpt-4',
            'gpt-4' => 'gpt-3.5-turbo',
        ];
        
        return $fallbackChains[$currentModel] ?? $currentModel;
    }
    
    /**
     * Record retry attempt
     */
    private function recordRetry(array $context, \Throwable $e, int $attempt): void
    {
        $key = $this->getContextKey($context);
        
        if (!isset($this->retryHistory[$key])) {
            $this->retryHistory[$key] = [];
        }
        
        $this->retryHistory[$key][] = [
            'attempt' => $attempt,
            'error' => get_class($e),
            'message' => $e->getMessage(),
            'timestamp' => microtime(true),
        ];
    }
    
    /**
     * Clear retry history
     */
    private function clearRetryHistory(array $context): void
    {
        $key = $this->getContextKey($context);
        unset($this->retryHistory[$key]);
    }
    
    /**
     * Handle exhausted retries
     */
    private function handleExhaustedRetries(?\Throwable $lastException, array $context): void
    {
        $this->logger->error('All retry attempts exhausted', [
            'last_error' => $lastException?->getMessage(),
            'retry_history' => $this->retryHistory[$this->getContextKey($context)] ?? [],
        ]);
        
        // Save checkpoint for manual recovery
        if ($this->config['save_on_failure']) {
            $this->saveFailureCheckpoint($context, $lastException);
        }
        
        // Emit failure event if available
        if ($this->events) {
            $this->events->dispatch('error_recovery.exhausted', [
                'error' => $lastException,
                'context' => $context,
                'history' => $this->retryHistory,
            ]);
        }
    }
    
    /**
     * Save failure checkpoint for manual recovery
     */
    private function saveFailureCheckpoint(array $context, ?\Throwable $exception): void
    {
        $checkpoint = [
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => $context,
            'state' => $this->captureState($context),
            'error' => [
                'class' => $exception ? get_class($exception) : null,
                'message' => $exception?->getMessage(),
                'trace' => $exception?->getTraceAsString(),
            ],
            'retry_history' => $this->retryHistory[$this->getContextKey($context)] ?? [],
        ];
        
        $path = $this->config['checkpoint_path'] ?? sys_get_temp_dir() . '/superagent/recovery';
        $filename = $path . '/checkpoint_' . uniqid() . '.json';
        @mkdir(dirname($filename), 0755, true);
        file_put_contents($filename, json_encode($checkpoint, JSON_PRETTY_PRINT));
        
        $this->logger->info("Failure checkpoint saved to: {$filename}");
    }
    
    /**
     * Get unique key for context
     */
    private function getContextKey(array $context): string
    {
        return md5(serialize($context));
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'max_retries' => 3,
            'default_recoverable' => true,
            'checkpoint_enabled' => true,
            'max_checkpoints' => 5,
            'save_on_failure' => true,
            'unrecoverable_errors' => [
                \InvalidArgumentException::class,
                \LogicException::class,
            ],
            'recoverable_patterns' => [
                '/rate limit/i',
                '/timeout/i',
                '/connection/i',
                '/temporary/i',
                '/overloaded/i',
            ],
            'fallback_models' => [],
            'retry_strategies' => [],
        ];
    }
}