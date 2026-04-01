<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

class HookResult
{
    public function __construct(
        public readonly bool $continue = true,
        public readonly bool $suppressOutput = false,
        public readonly ?string $stopReason = null,
        public readonly ?string $systemMessage = null,
        public readonly ?array $updatedInput = null,
        public readonly ?array $additionalContext = null,
        public readonly ?array $watchPaths = null,
        public readonly ?string $errorMessage = null,
    ) {}
    
    /**
     * Create a result that continues execution
     */
    public static function continue(
        ?string $systemMessage = null,
        ?array $updatedInput = null,
    ): self {
        return new self(
            continue: true,
            systemMessage: $systemMessage,
            updatedInput: $updatedInput,
        );
    }
    
    /**
     * Create a result that stops execution
     */
    public static function stop(
        string $stopReason,
        ?string $systemMessage = null,
    ): self {
        return new self(
            continue: false,
            stopReason: $stopReason,
            systemMessage: $systemMessage,
        );
    }
    
    /**
     * Create an error result
     */
    public static function error(string $errorMessage): self
    {
        return new self(
            continue: false,
            errorMessage: $errorMessage,
            stopReason: 'Hook execution error',
        );
    }
    
    /**
     * Merge multiple hook results
     * If any hook says stop, the merged result is stop
     * System messages and updated inputs are accumulated
     */
    public static function merge(array $results): self
    {
        $continue = true;
        $suppressOutput = false;
        $stopReason = null;
        $systemMessages = [];
        $updatedInput = [];
        $additionalContext = [];
        $watchPaths = [];
        $errorMessages = [];
        
        foreach ($results as $result) {
            if (!$result->continue) {
                $continue = false;
                $stopReason = $result->stopReason ?? $stopReason;
            }
            
            if ($result->suppressOutput) {
                $suppressOutput = true;
            }
            
            if ($result->systemMessage !== null) {
                $systemMessages[] = $result->systemMessage;
            }
            
            if ($result->updatedInput !== null) {
                $updatedInput = array_merge($updatedInput, $result->updatedInput);
            }
            
            if ($result->additionalContext !== null) {
                $additionalContext = array_merge($additionalContext, $result->additionalContext);
            }
            
            if ($result->watchPaths !== null) {
                $watchPaths = array_merge($watchPaths, $result->watchPaths);
            }
            
            if ($result->errorMessage !== null) {
                $errorMessages[] = $result->errorMessage;
            }
        }
        
        return new self(
            continue: $continue,
            suppressOutput: $suppressOutput,
            stopReason: $stopReason,
            systemMessage: empty($systemMessages) ? null : implode("\n", $systemMessages),
            updatedInput: empty($updatedInput) ? null : $updatedInput,
            additionalContext: empty($additionalContext) ? null : $additionalContext,
            watchPaths: empty($watchPaths) ? null : array_unique($watchPaths),
            errorMessage: empty($errorMessages) ? null : implode("\n", $errorMessages),
        );
    }
    
    public function toArray(): array
    {
        return array_filter([
            'continue' => $this->continue,
            'suppress_output' => $this->suppressOutput,
            'stop_reason' => $this->stopReason,
            'system_message' => $this->systemMessage,
            'updated_input' => $this->updatedInput,
            'additional_context' => $this->additionalContext,
            'watch_paths' => $this->watchPaths,
            'error_message' => $this->errorMessage,
        ], fn($value) => $value !== null && $value !== false);
    }
}