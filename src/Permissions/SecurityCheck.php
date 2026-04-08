<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

/**
 * Interface for individual bash security checks.
 *
 * Extracted from the monolithic BashSecurityValidator to enable:
 * - Composable chains: add/remove checks per environment
 * - Custom checks: third-party security rules
 * - Testing: isolate individual checks
 */
interface SecurityCheck
{
    /**
     * Get the numeric check ID (for logging/auditing).
     */
    public function getCheckId(): int;

    /**
     * Get a human-readable name for this check.
     */
    public function getName(): string;

    /**
     * Run the security check against a command.
     *
     * @return SecurityCheckResult|null  Result if check triggered, null to continue chain
     */
    public function check(ValidationContext $context): ?SecurityCheckResult;
}
