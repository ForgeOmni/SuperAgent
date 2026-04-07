<?php

declare(strict_types=1);

namespace SuperAgent\Permissions;

/**
 * Evaluates path rules against file paths.
 * Used as part of the PermissionEngine evaluation chain.
 */
class PathRuleEvaluator
{
    /** @var PathRule[] */
    private array $rules = [];

    /** @var CommandDenyPattern[] */
    private array $commandDenyPatterns = [];

    /**
     * @param PathRule[] $rules
     * @param CommandDenyPattern[] $commandDenyPatterns
     */
    public function __construct(array $rules = [], array $commandDenyPatterns = [])
    {
        $this->rules = $rules;
        $this->commandDenyPatterns = $commandDenyPatterns;
    }

    /**
     * Evaluate path rules for a given file path.
     * Returns null if no rule matches (let other evaluators decide).
     * Deny rules take precedence over allow rules.
     */
    public function evaluatePath(string $filePath): ?PermissionDecision
    {
        $matched = false;
        foreach ($this->rules as $rule) {
            if ($rule->matches($filePath)) {
                if (!$rule->allow) {
                    return PermissionDecision::deny("Path denied by rule: {$rule->pattern}");
                }
                $matched = true;
            }
        }
        // Allow rules don't grant permission on their own,
        // they just don't block. Return null to let other evaluators decide.
        return null;
    }

    /**
     * Evaluate command deny patterns for a given command.
     */
    public function evaluateCommand(string $command): ?PermissionDecision
    {
        foreach ($this->commandDenyPatterns as $pattern) {
            if ($pattern->matches($command)) {
                return PermissionDecision::deny("Command denied by pattern: {$pattern->pattern}");
            }
        }
        return null;
    }

    public function addRule(PathRule $rule): void
    {
        $this->rules[] = $rule;
    }

    public function addCommandDenyPattern(CommandDenyPattern $pattern): void
    {
        $this->commandDenyPatterns[] = $pattern;
    }

    /** @return PathRule[] */
    public function getRules(): array
    {
        return $this->rules;
    }

    /** @return CommandDenyPattern[] */
    public function getCommandDenyPatterns(): array
    {
        return $this->commandDenyPatterns;
    }

    /**
     * Factory from config array.
     */
    public static function fromConfig(array $config = []): self
    {
        $rules = [];
        foreach ($config['path_rules'] ?? [] as $ruleData) {
            $rules[] = PathRule::fromArray($ruleData);
        }

        $denyPatterns = [];
        foreach ($config['denied_commands'] ?? [] as $pattern) {
            $denyPatterns[] = new CommandDenyPattern($pattern);
        }

        return new self($rules, $denyPatterns);
    }
}
