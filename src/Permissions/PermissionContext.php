<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

use Illuminate\Support\Collection;

class PermissionContext
{
    /**
     * @var Collection<PermissionRule>
     */
    public Collection $alwaysAllowRules;
    
    /**
     * @var Collection<PermissionRule>
     */
    public Collection $alwaysDenyRules;
    
    /**
     * @var Collection<PermissionRule>
     */
    public Collection $alwaysAskRules;
    
    public function __construct(
        public readonly PermissionMode $mode = PermissionMode::DEFAULT,
        public readonly array $additionalWorkingDirectories = [],
        ?Collection $alwaysAllowRules = null,
        ?Collection $alwaysDenyRules = null,
        ?Collection $alwaysAskRules = null,
        public readonly bool $shouldAvoidPermissionPrompts = false,
    ) {
        $this->alwaysAllowRules = $alwaysAllowRules ?? collect();
        $this->alwaysDenyRules = $alwaysDenyRules ?? collect();
        $this->alwaysAskRules = $alwaysAskRules ?? collect();
    }
    
    public function addAllowRule(PermissionRule $rule): void
    {
        $this->alwaysAllowRules->push($rule);
    }
    
    public function addDenyRule(PermissionRule $rule): void
    {
        $this->alwaysDenyRules->push($rule);
    }
    
    public function addAskRule(PermissionRule $rule): void
    {
        $this->alwaysAskRules->push($rule);
    }
    
    public function withMode(PermissionMode $mode): self
    {
        return new self(
            mode: $mode,
            additionalWorkingDirectories: $this->additionalWorkingDirectories,
            alwaysAllowRules: $this->alwaysAllowRules,
            alwaysDenyRules: $this->alwaysDenyRules,
            alwaysAskRules: $this->alwaysAskRules,
            shouldAvoidPermissionPrompts: $this->shouldAvoidPermissionPrompts,
        );
    }
}