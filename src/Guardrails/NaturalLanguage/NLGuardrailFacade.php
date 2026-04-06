<?php

declare(strict_types=1);

namespace SuperAgent\Guardrails\NaturalLanguage;

final class NLGuardrailFacade
{
    /** @var string[] */
    private array $rules = [];
    private NLGuardrailCompiler $compiler;

    private function __construct()
    {
        $this->compiler = new NLGuardrailCompiler();
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Add a natural language rule.
     */
    public function rule(string $naturalLanguageRule): self
    {
        $this->rules[] = $naturalLanguageRule;
        return $this;
    }

    /**
     * Add multiple rules at once.
     */
    public function rules(array $rules): self
    {
        foreach ($rules as $rule) {
            $this->rules[] = $rule;
        }
        return $this;
    }

    /**
     * Compile all rules into a CompiledRuleSet.
     */
    public function compile(): CompiledRuleSet
    {
        return $this->compiler->compileAll($this->rules);
    }

    /**
     * Export as YAML string.
     */
    public function toYaml(): string
    {
        return $this->compile()->toYaml();
    }

    /**
     * Get rules that need human review.
     *
     * @return CompiledRule[]
     */
    public function getWarnings(): array
    {
        return $this->compile()->getNeedsReview();
    }

    /**
     * Get the count of registered rules.
     */
    public function count(): int
    {
        return count($this->rules);
    }
}
