<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

/**
 * Composable chain of security checks for bash commands.
 *
 * Decomposes the monolithic BashSecurityValidator into a chain of
 * independent SecurityCheck instances. Supports:
 * - Adding/removing individual checks
 * - Custom check insertion at any position
 * - Check disabling by ID or name
 * - Delegation to the existing BashSecurityValidator for backward compatibility
 *
 * Usage:
 *   // Use existing validator (default — full backward compatibility)
 *   $chain = SecurityCheckChain::fromValidator(new BashSecurityValidator());
 *
 *   // Or build custom chain
 *   $chain = new SecurityCheckChain();
 *   $chain->add(new CommandSubstitutionCheck());
 *   $chain->add(new CustomOrgPolicyCheck());
 *   $result = $chain->validate($command);
 */
class SecurityCheckChain
{
    /** @var SecurityCheck[] */
    private array $checks = [];

    /** @var int[] Check IDs to skip */
    private array $disabledIds = [];

    /** @var string[] Check names to skip */
    private array $disabledNames = [];

    /**
     * Create a chain that delegates to the existing BashSecurityValidator.
     * This provides full backward compatibility while enabling composability.
     */
    public static function fromValidator(BashSecurityValidator $validator): self
    {
        $chain = new self();
        $chain->add(new LegacyValidatorCheck($validator));
        return $chain;
    }

    /**
     * Add a security check to the chain.
     */
    public function add(SecurityCheck $check): self
    {
        $this->checks[] = $check;
        return $this;
    }

    /**
     * Insert a check at a specific position.
     */
    public function insertAt(int $position, SecurityCheck $check): self
    {
        array_splice($this->checks, $position, 0, [$check]);
        return $this;
    }

    /**
     * Remove a check by its ID.
     */
    public function disableById(int $checkId): self
    {
        $this->disabledIds[] = $checkId;
        return $this;
    }

    /**
     * Remove a check by name.
     */
    public function disableByName(string $name): self
    {
        $this->disabledNames[] = $name;
        return $this;
    }

    /**
     * Re-enable a previously disabled check.
     */
    public function enable(int $checkId): self
    {
        $this->disabledIds = array_filter($this->disabledIds, fn($id) => $id !== $checkId);
        return $this;
    }

    /**
     * Run all checks in the chain against a command.
     *
     * Returns the first denial found, or passthrough if all checks pass.
     */
    public function validate(string $command): SecurityCheckResult
    {
        $context = $this->buildContext($command);

        foreach ($this->checks as $check) {
            if ($this->isDisabled($check)) {
                continue;
            }

            $result = $check->check($context);
            if ($result !== null && $result->decision !== 'passthrough') {
                return $result;
            }
        }

        return SecurityCheckResult::passthrough();
    }

    /**
     * Get all registered checks.
     *
     * @return SecurityCheck[]
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * Get the count of active (non-disabled) checks.
     */
    public function getActiveCheckCount(): int
    {
        $count = 0;
        foreach ($this->checks as $check) {
            if (!$this->isDisabled($check)) {
                $count++;
            }
        }
        return $count;
    }

    private function isDisabled(SecurityCheck $check): bool
    {
        return in_array($check->getCheckId(), $this->disabledIds, true)
            || in_array($check->getName(), $this->disabledNames, true);
    }

    /**
     * Build validation context from command string.
     * Delegates to a simple extraction for the chain; the legacy validator
     * builds its own richer context internally.
     */
    private function buildContext(string $command): ValidationContext
    {
        $trimmed = trim($command);
        $baseCommand = preg_split('/\s+/', preg_replace('/^(\w+=\S+\s+)+/', '', $trimmed), 2)[0] ?? '';

        return new ValidationContext(
            originalCommand: $command,
            baseCommand: $baseCommand,
            unquotedContent: $command,
            fullyUnquotedContent: $command,
            unquotedKeepQuoteChars: $command,
        );
    }
}
