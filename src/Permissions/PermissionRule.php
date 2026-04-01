<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

class PermissionRule
{
    public function __construct(
        public readonly PermissionRuleSource $source,
        public readonly PermissionBehavior $ruleBehavior,
        public readonly PermissionRuleValue $ruleValue,
    ) {}
    
    public function matches(string $toolName, ?string $content = null): bool
    {
        if ($this->ruleValue->toolName !== $toolName) {
            return false;
        }
        
        if ($this->ruleValue->ruleContent === null) {
            return true;
        }
        
        if ($content === null) {
            return false;
        }
        
        $pattern = $this->ruleValue->ruleContent;
        
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            return str_starts_with($content, $prefix);
        }
        
        return $content === $pattern;
    }
    
    public function toString(): string
    {
        return $this->ruleValue->toString();
    }
}