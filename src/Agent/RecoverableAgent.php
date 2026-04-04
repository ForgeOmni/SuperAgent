<?php

namespace SuperAgent\Agent;

use SuperAgent\Agent;
use SuperAgent\ErrorRecovery\ErrorRecoveryManager;
use SuperAgent\ErrorRecovery\ErrorClassifier;
use SuperAgent\AgentResult;
use SuperAgent\Messages\UserMessage;
use SuperAgent\Exceptions\RecoverableException;

class RecoverableAgent extends Agent
{
    protected ErrorRecoveryManager $errorRecovery;
    protected ErrorClassifier $errorClassifier;
    protected array $recoveryConfig;
    
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        
        // Initialize error recovery
        $this->recoveryConfig = $config['error_recovery'] ?? static::config('error_recovery', []);
        $this->errorRecovery = new ErrorRecoveryManager($this->recoveryConfig);
        $this->errorClassifier = new ErrorClassifier();
    }
    
    /**
     * Execute query with automatic error recovery
     */
    public function query(string $query): AgentResult
    {
        if (!$this->recoveryConfig['enabled'] ?? true) {
            // Error recovery disabled, use parent implementation
            return parent::query($query);
        }
        
        // Create recovery context
        $context = [
            'agent' => $this,
            'provider' => $this->provider,
            'query' => $query,
            'type' => 'query',
        ];
        
        try {
            // Execute with recovery
            return $this->errorRecovery->execute(
                fn() => parent::query($query),
                $context
            );
        } catch (RecoverableException $e) {
            // All recovery attempts exhausted
            $this->handleRecoveryFailure($e, $context);
            throw $e;
        }
    }
    
    /**
     * Run agent loop with error recovery
     */
    public function run(string $prompt, array $options = []): AgentResult
    {
        if (!$this->recoveryConfig['enabled'] ?? true) {
            return parent::run($prompt, $options);
        }
        
        $context = [
            'agent' => $this,
            'provider' => $this->provider,
            'prompt' => $prompt,
            'options' => $options,
            'type' => 'run',
        ];
        
        try {
            return $this->errorRecovery->execute(
                fn() => $this->executeRun($prompt, $options),
                $context
            );
        } catch (RecoverableException $e) {
            $this->handleRecoveryFailure($e, $context);
            throw $e;
        }
    }
    
    /**
     * Execute the actual run operation
     */
    protected function executeRun(string $prompt, array $options = []): AgentResult
    {
        // Add initial user message
        $this->messages[] = new UserMessage($prompt);
        
        $stopReason = null;
        $lastAssistantMessage = null;
        
        while ($this->turnCount < $this->maxTurns) {
            $this->turnCount++;
            
            try {
                // Create checkpoint before each turn
                if ($this->recoveryConfig['checkpoint_enabled'] ?? true) {
                    $this->createTurnCheckpoint();
                }
                
                // Get LLM response
                $response = $this->provider->complete(
                    messages: $this->messages,
                    tools: $this->getEnabledTools(),
                    systemPrompt: $this->systemPrompt,
                    options: array_merge($this->options, $options)
                );
                
                // Process response
                $lastAssistantMessage = $response->message;
                $this->messages[] = $lastAssistantMessage;
                
                // Check stop reason
                $stopReason = $response->stopReason;
                
                if ($stopReason === 'stop' || empty($lastAssistantMessage->toolCalls)) {
                    break;
                }
                
                // Execute tool calls with recovery
                $this->executeToolCallsWithRecovery($lastAssistantMessage->toolCalls);
                
            } catch (\Throwable $e) {
                // Classify error
                $classifiedError = $this->errorClassifier->classify($e);
                
                // Check if we should retry this turn
                if ($this->shouldRetryTurn($classifiedError)) {
                    $this->turnCount--; // Don't count failed turn
                    $this->restoreFromTurnCheckpoint();
                    continue;
                }
                
                throw $e;
            }
        }
        
        return new AgentResult(
            content: $lastAssistantMessage?->content ?? '',
            messages: $this->messages,
            stopReason: $stopReason,
            toolCalls: $lastAssistantMessage?->toolCalls ?? [],
            metadata: [
                'turn_count' => $this->turnCount,
                'recovery_attempts' => $this->getRecoveryAttempts(),
            ]
        );
    }
    
    /**
     * Execute tool calls with individual error recovery
     */
    protected function executeToolCallsWithRecovery(array $toolCalls): void
    {
        $results = [];
        
        foreach ($toolCalls as $toolCall) {
            try {
                // Execute tool with recovery
                $result = $this->errorRecovery->execute(
                    fn() => $this->executeTool($toolCall),
                    ['tool' => $toolCall->name, 'params' => $toolCall->input]
                );
                
                $results[] = [
                    'id' => $toolCall->id,
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                // Tool execution failed even with recovery
                $results[] = [
                    'id' => $toolCall->id,
                    'result' => new ToolResult(
                        success: false,
                        data: ['error' => $e->getMessage()],
                    ),
                ];
                
                // Log tool failure
                $this->logToolFailure($toolCall, $e);
            }
        }
        
        // Add results to messages
        if (!empty($results)) {
            $this->messages[] = new ToolResultMessage($results);
        }
    }
    
    /**
     * Create checkpoint before turn
     */
    protected function createTurnCheckpoint(): void
    {
        $this->turnCheckpoint = [
            'messages' => $this->messages,
            'turn_count' => $this->turnCount,
            'total_cost' => $this->totalCostUsd,
        ];
    }
    
    /**
     * Restore from turn checkpoint
     */
    protected function restoreFromTurnCheckpoint(): void
    {
        if (isset($this->turnCheckpoint)) {
            $this->messages = $this->turnCheckpoint['messages'];
            $this->turnCount = $this->turnCheckpoint['turn_count'];
            $this->totalCostUsd = $this->turnCheckpoint['total_cost'];
        }
    }
    
    /**
     * Check if we should retry the current turn
     */
    protected function shouldRetryTurn(\Throwable $error): bool
    {
        // Temporary errors that warrant turn retry
        $retryableErrors = [
            'rate limit',
            'timeout',
            'connection',
            'temporary',
        ];
        
        $message = strtolower($error->getMessage());
        foreach ($retryableErrors as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle recovery failure
     */
    protected function handleRecoveryFailure(RecoverableException $e, array $context): void
    {
        // Log failure details
        if (method_exists($this, 'logger')) {
            $this->logger()->error('Error recovery exhausted', [
                'context' => $context,
                'history' => $e->getRetryHistory(),
                'checkpoint' => $e->getCheckpoint(),
            ]);
        }
        
        // Save state for manual recovery
        if ($this->recoveryConfig['save_on_failure'] ?? true) {
            $this->saveFailureState($e, $context);
        }
    }
    
    /**
     * Save failure state for manual recovery
     */
    protected function saveFailureState(RecoverableException $e, array $context): void
    {
        $state = [
            'timestamp' => date('Y-m-d H:i:s'),
            'messages' => $this->messages,
            'turn_count' => $this->turnCount,
            'context' => $context,
            'error' => [
                'message' => $e->getMessage(),
                'history' => $e->getRetryHistory(),
            ],
        ];
        
        $path = $this->recoveryConfig['checkpoint_path'] ?? storage_path('superagent/recovery');
        $filename = $path . '/failure_' . uniqid() . '.json';
        
        @mkdir($path, 0755, true);
        file_put_contents($filename, json_encode($state, JSON_PRETTY_PRINT));
    }
    
    /**
     * Log tool execution failure
     */
    protected function logToolFailure($toolCall, \Throwable $e): void
    {
        if (method_exists($this, 'logger')) {
            $this->logger()->warning('Tool execution failed after recovery', [
                'tool' => $toolCall->name,
                'params' => $toolCall->input,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get recovery attempt statistics
     */
    protected function getRecoveryAttempts(): array
    {
        // This would be tracked by the ErrorRecoveryManager
        return [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
        ];
    }
    
    /**
     * Compact context (called by recovery strategy)
     */
    public function compactContext(): void
    {
        // Implement context compaction logic
        // This would remove old tool results, compress messages, etc.
        $originalCount = count($this->messages);
        
        // Example: Keep only last N messages
        if (count($this->messages) > 20) {
            $this->messages = array_slice($this->messages, -20);
        }
        
        if (method_exists($this, 'logger')) {
            $this->logger()->info('Context compacted', [
                'original' => $originalCount,
                'new' => count($this->messages),
            ]);
        }
    }
}