<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

class CallbackHook implements HookInterface
{
    private bool $hasExecuted = false;
    
    /**
     * @var callable(HookInput, ?int): HookResult
     */
    private $callback;
    
    public function __construct(
        callable $callback,
        private int $timeout = 30,
        private bool $once = false,
        private ?string $condition = null,
    ) {
        $this->callback = $callback;
    }
    
    public function execute(HookInput $input, ?int $timeout = null): HookResult
    {
        if ($this->once && $this->hasExecuted) {
            return HookResult::continue('Hook already executed (once=true)');
        }
        
        try {
            // Set timeout for callback execution
            $actualTimeout = $timeout ?? $this->timeout;
            
            // In PHP, we can use pcntl_alarm for timeout, but it's not always available
            // For now, we'll execute directly and rely on the callback to be well-behaved
            $result = ($this->callback)($input, $actualTimeout);
            
            if (!($result instanceof HookResult)) {
                // If callback doesn't return HookResult, wrap the response
                if (is_array($result)) {
                    return new HookResult(
                        continue: $result['continue'] ?? true,
                        suppressOutput: $result['suppress_output'] ?? false,
                        stopReason: $result['stop_reason'] ?? null,
                        systemMessage: $result['system_message'] ?? null,
                        updatedInput: $result['updated_input'] ?? null,
                        additionalContext: $result['additional_context'] ?? null,
                    );
                }
                
                return HookResult::continue(is_string($result) ? $result : null);
            }
            
            $this->hasExecuted = true;
            
            return $result;
        } catch (\Exception $e) {
            return HookResult::error("Callback hook error: " . $e->getMessage());
        }
    }
    
    public function getType(): HookType
    {
        return HookType::CALLBACK;
    }
    
    public function matches(string $toolName = null, array $context = []): bool
    {
        return true;
    }
    
    public function isAsync(): bool
    {
        return false; // Callbacks are synchronous in PHP
    }
    
    public function isOnce(): bool
    {
        return $this->once;
    }
    
    public function getCondition(): ?string
    {
        return $this->condition;
    }
}