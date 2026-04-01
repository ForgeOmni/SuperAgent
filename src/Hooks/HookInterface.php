<?php

declare(strict_types=1);

namespace SuperAgent\Hooks;

interface HookInterface
{
    /**
     * Execute the hook with the given input
     */
    public function execute(HookInput $input, ?int $timeout = null): HookResult;
    
    /**
     * Get the hook type
     */
    public function getType(): HookType;
    
    /**
     * Check if the hook matches the given context
     */
    public function matches(string $toolName = null, array $context = []): bool;
    
    /**
     * Check if this is an async hook
     */
    public function isAsync(): bool;
    
    /**
     * Check if this hook should only run once
     */
    public function isOnce(): bool;
    
    /**
     * Get the hook's conditional expression (if any)
     */
    public function getCondition(): ?string;
}