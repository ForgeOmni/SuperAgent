<?php

namespace SuperAgent\Traits;

use SuperAgent\ErrorRecovery\ErrorRecoveryManager;
use SuperAgent\ErrorRecovery\ErrorClassifier;

trait ErrorRecoveryTrait
{
    protected ?ErrorRecoveryManager $errorRecovery = null;
    protected ?ErrorClassifier $errorClassifier = null;
    
    /**
     * Initialize error recovery
     */
    protected function initializeErrorRecovery(array $config = []): void
    {
        $this->errorRecovery = new ErrorRecoveryManager($config);
        $this->errorClassifier = new ErrorClassifier();
    }
    
    /**
     * Execute with error recovery
     */
    protected function executeWithRecovery(callable $operation, array $context = []): mixed
    {
        if (!$this->errorRecovery) {
            $this->initializeErrorRecovery();
        }
        
        // Add agent context
        $context['agent'] = $this;
        $context['provider'] = $this->provider ?? null;
        
        return $this->errorRecovery->execute($operation, $context);
    }
    
    /**
     * Handle error with classification
     */
    protected function handleError(\Throwable $error): void
    {
        if (!$this->errorClassifier) {
            $this->errorClassifier = new ErrorClassifier();
        }
        
        // Classify error
        $classifiedError = $this->errorClassifier->classify($error);
        
        // Get suggested strategy
        $strategy = $this->errorClassifier->getSuggestedStrategy($classifiedError);
        
        // Log with context
        if (method_exists($this, 'logger')) {
            $this->logger()->error('Classified error', [
                'original' => get_class($error),
                'classified' => get_class($classifiedError),
                'strategy' => $strategy,
                'message' => $error->getMessage(),
            ]);
        }
        
        throw $classifiedError;
    }
    
    /**
     * Create checkpoint for recovery
     */
    protected function createRecoveryCheckpoint(): array
    {
        $checkpoint = [
            'timestamp' => microtime(true),
            'messages' => $this->messages ?? [],
            'turn_count' => $this->turnCount ?? 0,
            'context' => $this->context ?? [],
        ];
        
        if (isset($this->provider)) {
            $checkpoint['model'] = $this->provider->getModel();
        }
        
        return $checkpoint;
    }
    
    /**
     * Restore from checkpoint
     */
    protected function restoreFromCheckpoint(array $checkpoint): void
    {
        if (isset($checkpoint['messages'])) {
            $this->messages = $checkpoint['messages'];
        }
        
        if (isset($checkpoint['turn_count'])) {
            $this->turnCount = $checkpoint['turn_count'];
        }
        
        if (isset($checkpoint['context'])) {
            $this->context = $checkpoint['context'];
        }
        
        if (isset($checkpoint['model']) && isset($this->provider)) {
            $this->provider->setModel($checkpoint['model']);
        }
    }
}