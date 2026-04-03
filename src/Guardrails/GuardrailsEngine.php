<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails;

use SuperAgent\Guardrails\Context\RuntimeContext;
use SuperAgent\Guardrails\Rules\RuleGroup;

class GuardrailsEngine
{
    /** @var RuleGroup[] sorted by priority descending */
    private array $groups = [];

    private string $evaluationMode;

    public function __construct(GuardrailsConfig $config)
    {
        $this->groups = $config->getGroups();
        $this->evaluationMode = $config->getEvaluationMode();
    }

    /**
     * Evaluate all enabled rule groups against the given runtime context.
     *
     * In first_match mode: returns the result of the first matching rule.
     * In all_matching mode: returns a result containing all matched rules.
     */
    public function evaluate(RuntimeContext $context): GuardrailsResult
    {
        // Record this tool call in the rate tracker
        $context->rateTracker?->record($context->toolName);

        if ($this->evaluationMode === 'all_matching') {
            return $this->evaluateAllMatching($context);
        }

        return $this->evaluateFirstMatch($context);
    }

    /**
     * Reload the engine with a new configuration.
     */
    public function reload(GuardrailsConfig $config): void
    {
        $this->groups = $config->getGroups();
        $this->evaluationMode = $config->getEvaluationMode();
    }

    /**
     * Get the current rule groups.
     *
     * @return RuleGroup[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Get statistics about the loaded guardrails.
     *
     * @return array{groups: int, rules: int, enabled_groups: int}
     */
    public function getStatistics(): array
    {
        $totalRules = 0;
        $enabledGroups = 0;

        foreach ($this->groups as $group) {
            if ($group->enabled) {
                $enabledGroups++;
            }
            $totalRules += count($group->rules);
        }

        return [
            'groups' => count($this->groups),
            'rules' => $totalRules,
            'enabled_groups' => $enabledGroups,
        ];
    }

    private function evaluateFirstMatch(RuntimeContext $context): GuardrailsResult
    {
        foreach ($this->groups as $group) {
            $matched = $group->findFirstMatch($context);
            if ($matched !== null) {
                return GuardrailsResult::fromRule($matched, $group->name);
            }
        }

        return GuardrailsResult::noMatch();
    }

    private function evaluateAllMatching(RuntimeContext $context): GuardrailsResult
    {
        $allMatched = [];
        $firstMatch = null;
        $firstGroup = null;

        foreach ($this->groups as $group) {
            $matches = $group->findAllMatches($context);
            foreach ($matches as $rule) {
                if ($firstMatch === null) {
                    $firstMatch = $rule;
                    $firstGroup = $group->name;
                }
                $allMatched[] = $rule;
            }
        }

        if ($firstMatch === null) {
            return GuardrailsResult::noMatch();
        }

        return new GuardrailsResult(
            matched: true,
            action: $firstMatch->action,
            message: $firstMatch->message,
            matchedRule: $firstMatch,
            groupName: $firstGroup,
            params: $firstMatch->params,
            allMatched: $allMatched,
        );
    }
}
