<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

enum MemoryType: string
{
    case USER = 'user';
    case FEEDBACK = 'feedback';
    case PROJECT = 'project';
    case REFERENCE = 'reference';
    
    public function getDescription(): string
    {
        return match ($this) {
            self::USER => 'Information about the user\'s role, goals, responsibilities, and knowledge',
            self::FEEDBACK => 'Guidance about how to approach work - what to avoid and what to keep doing',
            self::PROJECT => 'Ongoing work, goals, initiatives, bugs, incidents not derivable from code',
            self::REFERENCE => 'Pointers to external systems and resources',
        };
    }
    
    public function getDefaultScope(): MemoryScope
    {
        return match ($this) {
            self::USER => MemoryScope::PRIVATE,
            self::FEEDBACK => MemoryScope::PRIVATE,
            self::PROJECT => MemoryScope::TEAM,
            self::REFERENCE => MemoryScope::TEAM,
        };
    }
    
    public function requiresStructure(): bool
    {
        return in_array($this, [self::FEEDBACK, self::PROJECT], true);
    }
    
    public function getExampleContent(): string
    {
        return match ($this) {
            self::USER => "User is a senior backend engineer with 10 years of Go experience.\nPrefers concise explanations with code examples.",
            self::FEEDBACK => "Don't use mocks for database tests.\n\n**Why:** Team policy is to test against real databases for reliability.\n\n**How to apply:** Use test containers or in-memory databases instead.",
            self::PROJECT => "Auth middleware rewrite in progress for OAuth 2.0 compliance.\n\n**Why:** Required for SOC2 certification by Q2.\n\n**How to apply:** All new auth features should use the new middleware patterns.",
            self::REFERENCE => "Bug tracking: Linear project at linear.app/company/project\nMonitoring: Grafana dashboard at grafana.company.com/dash/app-metrics",
        };
    }
}

enum MemoryScope: string
{
    case PRIVATE = 'private';
    case TEAM = 'team';
    
    public function isShared(): bool
    {
        return $this === self::TEAM;
    }
}