<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\Rules;

use SuperAgent\Guardrails\Context\RuntimeContext;

class RuleGroup
{
    /**
     * @param Rule[] $rules
     */
    public function __construct(
        public readonly string $name,
        public readonly array $rules,
        public readonly int $priority = 0,
        public readonly bool $enabled = true,
        public readonly ?string $description = null,
    ) {}

    /**
     * Find the first matching rule in this group.
     */
    public function findFirstMatch(RuntimeContext $context): ?Rule
    {
        if (!$this->enabled) {
            return null;
        }

        foreach ($this->rules as $rule) {
            if ($rule->matches($context)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Find all matching rules in this group.
     *
     * @return Rule[]
     */
    public function findAllMatches(RuntimeContext $context): array
    {
        if (!$this->enabled) {
            return [];
        }

        $matches = [];
        foreach ($this->rules as $rule) {
            if ($rule->matches($context)) {
                $matches[] = $rule;
            }
        }

        return $matches;
    }
}
