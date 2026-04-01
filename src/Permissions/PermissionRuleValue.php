<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

class PermissionRuleValue
{
    public function __construct(
        public readonly string $toolName,
        public readonly ?string $ruleContent = null,
    ) {}
    
    public function toString(): string
    {
        if ($this->ruleContent === null) {
            return $this->toolName;
        }
        
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->ruleContent);
        return "{$this->toolName}({$escaped})";
    }
    
    public static function fromString(string $rule): self
    {
        $parser = new PermissionRuleParser();
        return $parser->parse($rule);
    }
}