<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

/**
 * Adapter that wraps the existing BashSecurityValidator as a SecurityCheck.
 *
 * This enables the SecurityCheckChain to delegate to the full 23-check
 * validator while the decomposition is progressive. New custom checks
 * can be added before or after this adapter in the chain.
 */
class LegacyValidatorCheck implements SecurityCheck
{
    public function __construct(
        private BashSecurityValidator $validator,
    ) {}

    public function getCheckId(): int
    {
        return 0; // Meta-check: wraps all 23 individual checks
    }

    public function getName(): string
    {
        return 'legacy_validator';
    }

    public function check(ValidationContext $context): ?SecurityCheckResult
    {
        $result = $this->validator->validate($context->originalCommand);

        // Convert passthrough to null (continue chain)
        if ($result->decision === 'passthrough') {
            return null;
        }

        return $result;
    }
}
