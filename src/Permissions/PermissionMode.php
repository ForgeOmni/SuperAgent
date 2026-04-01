<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

enum PermissionMode: string
{
    case DEFAULT = 'default';
    case PLAN = 'plan';
    case ACCEPT_EDITS = 'acceptEdits';
    case BYPASS_PERMISSIONS = 'bypassPermissions';
    case DONT_ASK = 'dontAsk';
    case AUTO = 'auto';
    
    public function getTitle(): string
    {
        return match ($this) {
            self::DEFAULT => 'Standard Permissions',
            self::PLAN => 'Planning Mode',
            self::ACCEPT_EDITS => 'Accept Edits',
            self::BYPASS_PERMISSIONS => 'Bypass Permissions',
            self::DONT_ASK => "Don't Ask",
            self::AUTO => 'Auto Mode',
        };
    }
    
    public function getSymbol(): string
    {
        return match ($this) {
            self::DEFAULT => '🔒',
            self::PLAN => '📋',
            self::ACCEPT_EDITS => '✅',
            self::BYPASS_PERMISSIONS => '⚠️',
            self::DONT_ASK => '🚫',
            self::AUTO => '🤖',
        };
    }
    
    public function getColor(): string
    {
        return match ($this) {
            self::DEFAULT => 'green',
            self::PLAN => 'blue',
            self::ACCEPT_EDITS => 'cyan',
            self::BYPASS_PERMISSIONS => 'yellow',
            self::DONT_ASK => 'red',
            self::AUTO => 'magenta',
        };
    }
    
    public function isHeadless(): bool
    {
        return in_array($this, [self::DONT_ASK, self::AUTO], true);
    }
}