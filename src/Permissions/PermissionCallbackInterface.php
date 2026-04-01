<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

interface PermissionCallbackInterface
{
    /**
     * Called when permission is required from the user
     */
    public function askUserPermission(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): PermissionBehavior;
    
    /**
     * Called when auto mode needs to classify a permission
     */
    public function runAutoClassifier(string $prompt): bool;
    
    /**
     * Called when a permission is granted
     */
    public function onPermissionGranted(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): void;
    
    /**
     * Called when a permission is denied
     */
    public function onPermissionDenied(
        string $toolName,
        array $input,
        PermissionDecision $decision,
    ): void;
    
    /**
     * Get user's decision for a permission update suggestion
     */
    public function selectPermissionUpdate(array $suggestions): ?PermissionUpdate;
}