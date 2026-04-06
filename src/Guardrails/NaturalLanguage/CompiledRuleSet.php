<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\NaturalLanguage;

final class CompiledRuleSet
{
    /** @var CompiledRule[] */
    public readonly array $rules;
    public readonly array $warnings;
    public readonly int $totalRules;
    public readonly int $highConfidenceCount;
    public readonly int $needsReviewCount;

    /**
     * @param CompiledRule[] $rules
     */
    public function __construct(array $rules)
    {
        $this->rules = array_values($rules);
        $this->totalRules = count($this->rules);
        $this->warnings = array_values(array_filter($this->rules, fn(CompiledRule $r) => $r->needsReview));
        $this->highConfidenceCount = count(array_filter($this->rules, fn(CompiledRule $r) => $r->confidence >= 0.7));
        $this->needsReviewCount = count($this->warnings);
    }

    /**
     * Get rules grouped by group name.
     *
     * @return array<string, CompiledRule[]>
     */
    public function getGroups(): array
    {
        $groups = [];
        foreach ($this->rules as $rule) {
            $groups[$rule->groupName][] = $rule;
        }
        return $groups;
    }

    /**
     * @return CompiledRule[]
     */
    public function getHighConfidence(): array
    {
        return array_values(array_filter($this->rules, fn(CompiledRule $r) => $r->confidence >= 0.7));
    }

    /**
     * @return CompiledRule[]
     */
    public function getNeedsReview(): array
    {
        return $this->warnings;
    }

    public function toYaml(): string
    {
        $groups = $this->getGroups();
        $yaml = "# Auto-generated guardrails from natural language rules\n";
        $yaml .= "# Generated: " . date('c') . "\n\n";
        $yaml .= "groups:\n";

        foreach ($groups as $groupName => $rules) {
            $priority = $rules[0]->priority ?? 50;
            $yaml .= "  - name: nl_{$groupName}\n";
            $yaml .= "    priority: {$priority}\n";
            $yaml .= "    rules:\n";

            foreach ($rules as $rule) {
                $yaml .= "      # {$rule->originalText}" . ($rule->needsReview ? ' [NEEDS REVIEW]' : '') . "\n";
                foreach (explode("\n", $rule->toYaml()) as $line) {
                    if (trim($line) !== '' && !str_starts_with(trim($line), '#')) {
                        $yaml .= "      {$line}\n";
                    }
                }
            }
        }

        return $yaml;
    }

    public function toArray(): array
    {
        return [
            'total_rules' => $this->totalRules,
            'high_confidence' => $this->highConfidenceCount,
            'needs_review' => $this->needsReviewCount,
            'rules' => array_map(fn(CompiledRule $r) => $r->toArray(), $this->rules),
        ];
    }
}
