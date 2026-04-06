<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\NaturalLanguage;

final class NLGuardrailCompiler
{
    private RuleParser $parser;

    public function __construct(
        ?RuleParser $parser = null,
        private readonly array $config = [],
    ) {
        $this->parser = $parser ?? new RuleParser();
    }

    /**
     * Compile a single natural language rule.
     */
    public function compile(string $naturalLanguageRule): CompiledRule
    {
        $parsed = $this->parser->parse($naturalLanguageRule);

        $ruleDefinition = [
            'condition' => $this->buildCondition($parsed),
            'action' => $parsed->action,
            'message' => $parsed->message,
        ];

        return new CompiledRule(
            originalText: $parsed->originalText,
            ruleDefinition: $ruleDefinition,
            groupName: $parsed->groupName ?? 'custom',
            priority: $parsed->priority,
            confidence: $parsed->confidence,
            needsReview: $parsed->needsReview,
        );
    }

    /**
     * Compile multiple natural language rules into a rule set.
     */
    public function compileAll(array $rules): CompiledRuleSet
    {
        $compiled = [];
        foreach ($rules as $rule) {
            if (is_string($rule) && trim($rule) !== '') {
                $compiled[] = $this->compile($rule);
            }
        }
        return new CompiledRuleSet($compiled);
    }

    /**
     * Export compiled rules as a YAML string.
     */
    public function toYaml(CompiledRuleSet $rules): string
    {
        return $rules->toYaml();
    }

    private function buildCondition(ParsedRule $parsed): array
    {
        $condition = $parsed->conditions;

        if ($parsed->toolName !== null && !isset($condition['tool_name'])) {
            $condition['tool_name'] = $parsed->toolName;
        }

        // If condition is empty but we have a tool name, add it
        if (empty($condition) && $parsed->toolName !== null) {
            $condition = ['tool_name' => $parsed->toolName];
        }

        return $condition;
    }
}
