<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

enum HookEvent: string
{
    // Lifecycle hooks
    case SESSION_START = 'SessionStart';
    case SESSION_END = 'SessionEnd';
    case ON_STOP = 'OnStop';
    case ON_QUERY = 'OnQuery';
    case ON_MESSAGE = 'OnMessage';
    case ON_THINKING_COMPLETE = 'OnThinkingComplete';
    
    // Tool execution hooks
    case PRE_TOOL_USE = 'PreToolUse';
    case POST_TOOL_USE = 'PostToolUse';
    case POST_TOOL_USE_FAILURE = 'PostToolUseFailure';
    
    // Permission hooks
    case PERMISSION_REQUEST = 'PermissionRequest';
    case PERMISSION_DENIED = 'PermissionDenied';
    
    // User interaction hooks
    case USER_PROMPT_SUBMIT = 'UserPromptSubmit';
    case NOTIFICATION = 'Notification';
    
    // System hooks
    case PRE_COMPACT = 'PreCompact';
    case POST_COMPACT = 'PostCompact';
    case CONFIG_CHANGE = 'ConfigChange';
    
    // Task hooks
    case TASK_CREATED = 'TaskCreated';
    case TASK_COMPLETED = 'TaskCompleted';
    
    // File system hooks
    case CWD_CHANGED = 'CwdChanged';
    case FILE_CHANGED = 'FileChanged';
    
    public function getDescription(): string
    {
        return match ($this) {
            self::SESSION_START => 'Fired when a new session begins',
            self::SESSION_END => 'Fired when a session ends',
            self::ON_STOP => 'Fired when the agent is stopping',
            self::ON_QUERY => 'Fired when a query is received',
            self::ON_MESSAGE => 'Fired when a message is received',
            self::ON_THINKING_COMPLETE => 'Fired when extended thinking completes',
            self::PRE_TOOL_USE => 'Fired before a tool is executed',
            self::POST_TOOL_USE => 'Fired after successful tool execution',
            self::POST_TOOL_USE_FAILURE => 'Fired when tool execution fails',
            self::PERMISSION_REQUEST => 'Fired when permission is requested',
            self::PERMISSION_DENIED => 'Fired when permission is denied',
            self::USER_PROMPT_SUBMIT => 'Fired when user submits a prompt',
            self::NOTIFICATION => 'Fired for general notifications',
            self::PRE_COMPACT => 'Fired before conversation compacting',
            self::POST_COMPACT => 'Fired after conversation compacting',
            self::CONFIG_CHANGE => 'Fired when configuration changes',
            self::TASK_CREATED => 'Fired when a task is created',
            self::TASK_COMPLETED => 'Fired when a task completes',
            self::CWD_CHANGED => 'Fired when current directory changes',
            self::FILE_CHANGED => 'Fired when watched files change',
        };
    }
}