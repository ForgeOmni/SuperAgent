<?php

declare(strict_types=1);

namespace SuperAgent\Sandbox;

/**
 * OS-level sandbox seam (deepseek-harness-borrowed): consumers hand over the
 * exact shell command; the backend wraps it per-call and reports what it
 * enforces. Static analysis (BashSecurityValidator's 23 checks, the
 * permission DSL) screens intent; this seam confines actual runtime
 * behavior.
 */
interface SandboxInterface
{
    public function name(): string;

    /** Whether this backend can enforce on the current host. */
    public function isAvailable(): bool;

    /**
     * Wrap a shell command string so it executes under the sandbox.
     * Implementations MUST return a command that fails (rather than runs
     * unconfined) when the sandbox cannot be applied at execution time.
     */
    public function wrapCommand(string $command, SandboxSpec $spec): string;

    /** One-line human/model-facing description of what is enforced. */
    public function describeEnforcement(SandboxSpec $spec): string;
}
