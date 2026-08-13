<?php

declare(strict_types=1);

namespace SuperAgent\Sandbox;

/**
 * No-op backend: always available, enforces nothing. Used for mode=off and
 * as the auto-mode fallback on hosts with no real backend.
 */
class NullSandbox implements SandboxInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function wrapCommand(string $command, SandboxSpec $spec): string
    {
        return $command;
    }

    public function describeEnforcement(SandboxSpec $spec): string
    {
        return 'no sandbox — command runs unconfined';
    }
}
