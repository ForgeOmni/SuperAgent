<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

enum HookType: string
{
    case COMMAND = 'command';
    case PROMPT = 'prompt';
    case HTTP = 'http';
    case AGENT = 'agent';
    case CALLBACK = 'callback';
    case FUNCTION = 'function';
}