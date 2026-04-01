<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

enum PermissionBehavior: string
{
    case ALLOW = 'allow';
    case DENY = 'deny';
    case ASK = 'ask';
    case PASSTHROUGH = 'passthrough';
}