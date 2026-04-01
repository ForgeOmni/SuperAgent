<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

class HookInput
{
    public function __construct(
        public readonly HookEvent $hookEvent,
        public readonly string $sessionId,
        public readonly string $cwd,
        public readonly ?string $gitRepoRoot = null,
        public readonly array $additionalData = [],
    ) {}
    
    /**
     * Create input for PreToolUse hook
     */
    public static function preToolUse(
        string $sessionId,
        string $cwd,
        string $toolName,
        array $toolInput,
        string $toolUseId,
        ?string $gitRepoRoot = null,
    ): self {
        return new self(
            hookEvent: HookEvent::PRE_TOOL_USE,
            sessionId: $sessionId,
            cwd: $cwd,
            gitRepoRoot: $gitRepoRoot,
            additionalData: [
                'tool_name' => $toolName,
                'tool_input' => $toolInput,
                'tool_use_id' => $toolUseId,
            ],
        );
    }
    
    /**
     * Create input for PostToolUse hook
     */
    public static function postToolUse(
        string $sessionId,
        string $cwd,
        string $toolName,
        array $toolInput,
        string $toolUseId,
        mixed $toolOutput,
        ?string $gitRepoRoot = null,
    ): self {
        return new self(
            hookEvent: HookEvent::POST_TOOL_USE,
            sessionId: $sessionId,
            cwd: $cwd,
            gitRepoRoot: $gitRepoRoot,
            additionalData: [
                'tool_name' => $toolName,
                'tool_input' => $toolInput,
                'tool_use_id' => $toolUseId,
                'tool_output' => $toolOutput,
            ],
        );
    }
    
    /**
     * Create input for SessionStart hook
     */
    public static function sessionStart(
        string $sessionId,
        string $cwd,
        string $source,
        string $agentType,
        string $model,
        ?string $gitRepoRoot = null,
    ): self {
        return new self(
            hookEvent: HookEvent::SESSION_START,
            sessionId: $sessionId,
            cwd: $cwd,
            gitRepoRoot: $gitRepoRoot,
            additionalData: [
                'source' => $source,
                'agent_type' => $agentType,
                'model' => $model,
            ],
        );
    }
    
    /**
     * Create input for FileChanged hook
     */
    public static function fileChanged(
        string $sessionId,
        string $cwd,
        array $changedFiles,
        array $watchPaths,
        ?string $gitRepoRoot = null,
    ): self {
        return new self(
            hookEvent: HookEvent::FILE_CHANGED,
            sessionId: $sessionId,
            cwd: $cwd,
            gitRepoRoot: $gitRepoRoot,
            additionalData: [
                'changed_files' => $changedFiles,
                'watch_paths' => $watchPaths,
            ],
        );
    }
    
    public function toArray(): array
    {
        return [
            'hook_event_name' => $this->hookEvent->value,
            'session_id' => $this->sessionId,
            'cwd' => $this->cwd,
            'git_repo_root' => $this->gitRepoRoot,
            ...$this->additionalData,
        ];
    }
}