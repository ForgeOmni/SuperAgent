<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

enum PermissionRuleSource: string
{
    case SETTINGS = 'settings';
    case RUNTIME = 'runtime';
    case HOOK = 'hook';
}