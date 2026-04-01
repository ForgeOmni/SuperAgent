<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

class HookResult
{
    /**
     * @param bool $continue Whether execution should continue
     * @param bool $suppressOutput Whether to suppress tool output
     * @param string|null $stopReason Reason for stopping
     * @param string|null $systemMessage System message to inject
     * @param array|null $updatedInput Modified tool input (replaces original)
     * @param array|null $additionalContext Extra context to inject
     * @param array|null $watchPaths Paths to watch for changes
     * @param string|null $errorMessage Error message
     * @param string|null $permissionBehavior Permission decision: 'allow', 'deny', or 'ask'
     * @param string|null $permissionReason Reason for permission decision
     * @param bool $preventContinuation Prevent the agent loop from continuing after this tool
     */
    public function __construct(
        public readonly bool $continue = true,
        public readonly bool $suppressOutput = false,
        public readonly ?string $stopReason = null,
        public readonly ?string $systemMessage = null,
        public readonly ?array $updatedInput = null,
        public readonly ?array $additionalContext = null,
        public readonly ?array $watchPaths = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $permissionBehavior = null,
        public readonly ?string $permissionReason = null,
        public readonly bool $preventContinuation = false,
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
     * Create a result that allows the tool to execute (bypasses permission prompt).
     * Note: hook allow does NOT bypass settings deny rules.
     */
    public static function allow(?array $updatedInput = null, ?string $reason = null): self
    {
        return new self(
            continue: true,
            updatedInput: $updatedInput,
            permissionBehavior: 'allow',
            permissionReason: $reason,
        );
    }

    /**
     * Create a result that denies the tool execution.
     */
    public static function deny(string $reason): self
    {
        return new self(
            continue: false,
            permissionBehavior: 'deny',
            permissionReason: $reason,
            stopReason: $reason,
        );
    }

    /**
     * Create a result that forces a permission prompt to the user.
     */
    public static function ask(string $reason, ?array $updatedInput = null): self
    {
        return new self(
            continue: true,
            updatedInput: $updatedInput,
            permissionBehavior: 'ask',
            permissionReason: $reason,
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
        $permissionBehavior = null;
        $permissionReason = null;
        $preventContinuation = false;

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

            // Permission: deny wins over ask, ask wins over allow
            if ($result->permissionBehavior !== null) {
                if ($result->permissionBehavior === 'deny') {
                    $permissionBehavior = 'deny';
                    $permissionReason = $result->permissionReason;
                } elseif ($result->permissionBehavior === 'ask' && $permissionBehavior !== 'deny') {
                    $permissionBehavior = 'ask';
                    $permissionReason = $result->permissionReason;
                } elseif ($permissionBehavior === null) {
                    $permissionBehavior = $result->permissionBehavior;
                    $permissionReason = $result->permissionReason;
                }
            }

            if ($result->preventContinuation) {
                $preventContinuation = true;
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
            permissionBehavior: $permissionBehavior,
            permissionReason: $permissionReason,
            preventContinuation: $preventContinuation,
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