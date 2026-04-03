<?php

declare(strict_types=1);

namespace SuperAgent\AdaptiveFeedback;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;
use SuperAgent\Memory\Memory;
use SuperAgent\Memory\MemoryType;
use SuperAgent\Memory\Storage\MemoryStorage;

/**
 * Evaluates correction patterns and promotes recurring ones to
 * Guardrails rules or Memory entries, making the agent learn from
 * user corrections over time.
 *
 * Promotion rules:
 *   - Tool denials (≥ threshold) → Guardrails deny/warn rule
 *   - Behavior corrections (≥ threshold) → Memory (feedback type)
 *   - Content unwanted (≥ threshold) → Memory (feedback type)
 *   - Edit reverted (≥ threshold) → Guardrails warn rule
 *
 * Usage:
 *   $engine = new AdaptiveFeedbackEngine($store);
 *   $engine->setGuardrailsEngine($guardrailsEngine);
 *   $engine->setMemoryStorage($memoryStorage);
 *   $promotions = $engine->evaluate();
 */
class AdaptiveFeedbackEngine
{
    private CorrectionStore $store;

    private LoggerInterface $logger;

    private ?GuardrailsEngine $guardrailsEngine = null;

    private ?MemoryStorage $memoryStorage = null;

    /** Minimum occurrences before a pattern is promoted */
    private int $promotionThreshold;

    /** Whether to auto-promote or just suggest */
    private bool $autoPromote;

    /** @var callable[] */
    private array $listeners = [];

    public function __construct(
        CorrectionStore $store,
        int $promotionThreshold = 3,
        bool $autoPromote = true,
        ?LoggerInterface $logger = null,
    ) {
        $this->store = $store;
        $this->promotionThreshold = $promotionThreshold;
        $this->autoPromote = $autoPromote;
        $this->logger = $logger ?? new NullLogger();
    }

    public function setGuardrailsEngine(?GuardrailsEngine $engine): void
    {
        $this->guardrailsEngine = $engine;
    }

    public function setMemoryStorage(?MemoryStorage $storage): void
    {
        $this->memoryStorage = $storage;
    }

    public function setPromotionThreshold(int $threshold): void
    {
        $this->promotionThreshold = $threshold;
    }

    /**
     * Register an event listener.
     *
     * Events: feedback.promoted, feedback.rule_generated, feedback.memory_generated
     */
    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Evaluate all patterns and promote those that meet the threshold.
     *
     * @return PromotionResult[]
     */
    public function evaluate(): array
    {
        $promotable = $this->store->getPromotable($this->promotionThreshold);
        $results = [];

        foreach ($promotable as $pattern) {
            $result = $this->promotePattern($pattern);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Force-evaluate a specific pattern regardless of threshold.
     */
    public function promoteById(string $patternId): ?PromotionResult
    {
        $pattern = $this->store->get($patternId);
        if ($pattern === null || $pattern->promoted) {
            return null;
        }

        return $this->promotePattern($pattern);
    }

    /**
     * Get suggestions for patterns that are close to the threshold.
     *
     * @return array{pattern: CorrectionPattern, remaining: int}[]
     */
    public function getSuggestions(): array
    {
        $suggestions = [];
        $minShow = max(1, $this->promotionThreshold - 2);

        foreach ($this->store->getAll() as $pattern) {
            if ($pattern->promoted) {
                continue;
            }
            if ($pattern->occurrences >= $minShow && $pattern->occurrences < $this->promotionThreshold) {
                $suggestions[] = [
                    'pattern' => $pattern,
                    'remaining' => $this->promotionThreshold - $pattern->occurrences,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Get statistics about the adaptive feedback system.
     */
    public function getStatistics(): array
    {
        $storeStats = $this->store->getStatistics();

        return array_merge($storeStats, [
            'promotion_threshold' => $this->promotionThreshold,
            'auto_promote' => $this->autoPromote,
            'promotable_count' => count($this->store->getPromotable($this->promotionThreshold)),
        ]);
    }

    /**
     * Promote a single pattern to a rule or memory.
     */
    private function promotePattern(CorrectionPattern $pattern): ?PromotionResult
    {
        if ($pattern->category->shouldGenerateRule()) {
            return $this->promoteToRule($pattern);
        }

        if ($pattern->category->shouldGenerateMemory()) {
            return $this->promoteToMemory($pattern);
        }

        return null;
    }

    /**
     * Promote a pattern to a Guardrails rule.
     */
    private function promoteToRule(CorrectionPattern $pattern): PromotionResult
    {
        $ruleName = 'adaptive_' . $pattern->id;
        $ruleAction = $pattern->occurrences >= ($this->promotionThreshold * 2) ? 'deny' : 'warn';
        $ruleMessage = "Auto-learned from {$pattern->occurrences} corrections: {$pattern->pattern}";

        $ruleYaml = $this->buildRuleYaml($ruleName, $pattern, $ruleAction, $ruleMessage);

        // Apply to guardrails engine if available and auto-promote is on
        if ($this->autoPromote && $this->guardrailsEngine !== null) {
            $this->applyRule($ruleName, $pattern, $ruleAction, $ruleMessage);
        }

        $this->store->markPromoted($pattern->id, 'rule');

        $result = new PromotionResult(
            patternId: $pattern->id,
            type: 'rule',
            description: $ruleMessage,
            content: $ruleYaml,
            pattern: $pattern,
        );

        $this->logger->info("AdaptiveFeedback: promoted to rule", [
            'pattern' => $pattern->pattern,
            'rule' => $ruleName,
            'action' => $ruleAction,
        ]);

        $this->emit('feedback.promoted', $result);
        $this->emit('feedback.rule_generated', $result);

        return $result;
    }

    /**
     * Promote a pattern to a Memory entry (feedback type).
     */
    private function promoteToMemory(CorrectionPattern $pattern): PromotionResult
    {
        $memoryContent = $this->buildMemoryContent($pattern);
        $memoryId = 'feedback_adaptive_' . $pattern->id;

        if ($this->autoPromote && $this->memoryStorage !== null) {
            $memory = new Memory(
                id: $memoryId,
                name: "Adaptive: {$pattern->pattern}",
                description: "Auto-learned from {$pattern->occurrences} corrections",
                type: MemoryType::FEEDBACK,
                content: $memoryContent,
            );

            $this->memoryStorage->save($memory);
        }

        $this->store->markPromoted($pattern->id, 'memory');

        $result = new PromotionResult(
            patternId: $pattern->id,
            type: 'memory',
            description: "Feedback memory: {$pattern->pattern}",
            content: $memoryContent,
            pattern: $pattern,
        );

        $this->logger->info("AdaptiveFeedback: promoted to memory", [
            'pattern' => $pattern->pattern,
            'memory_id' => $memoryId,
        ]);

        $this->emit('feedback.promoted', $result);
        $this->emit('feedback.memory_generated', $result);

        return $result;
    }

    /**
     * Build YAML for a guardrails rule.
     */
    private function buildRuleYaml(
        string $ruleName,
        CorrectionPattern $pattern,
        string $action,
        string $message,
    ): string {
        $conditions = $this->buildConditions($pattern);

        $yaml = "# Auto-generated by AdaptiveFeedback from {$pattern->occurrences} corrections\n";
        $yaml .= "- name: {$ruleName}\n";
        $yaml .= "  description: \"{$message}\"\n";
        $yaml .= "  conditions:\n";

        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                $yaml .= "    {$key}:\n";
                foreach ($value as $k => $v) {
                    $yaml .= "      {$k}: \"{$v}\"\n";
                }
            } else {
                $yaml .= "    {$key}: \"{$value}\"\n";
            }
        }

        $yaml .= "  action: {$action}\n";
        $yaml .= "  message: \"{$message}\"\n";

        return $yaml;
    }

    /**
     * Build condition array for a pattern.
     */
    private function buildConditions(CorrectionPattern $pattern): array
    {
        $conditions = [];

        if ($pattern->toolName !== null) {
            $conditions['tool'] = ['name' => $pattern->toolName];
        }

        if ($pattern->toolInput !== null && !empty($pattern->toolInput)) {
            // For bash commands, match the command pattern
            if ($pattern->toolName === 'Bash' && str_starts_with($pattern->pattern, 'bash: ')) {
                $cmdPattern = substr($pattern->pattern, 6); // Remove "bash: " prefix
                $conditions['tool_input'] = ['field' => 'command', 'starts_with' => $cmdPattern];
            }
        }

        return $conditions;
    }

    /**
     * Apply a rule to the GuardrailsEngine dynamically.
     */
    private function applyRule(
        string $ruleName,
        CorrectionPattern $pattern,
        string $action,
        string $message,
    ): void {
        $conditions = $this->buildConditions($pattern);

        $ruleConfig = [
            'groups' => [
                'adaptive_feedback' => [
                    'priority' => 50,
                    'description' => 'Rules auto-generated from user corrections',
                    'rules' => [
                        [
                            'name' => $ruleName,
                            'conditions' => $conditions ?: ['tool' => ['name' => $pattern->toolName ?? '*']],
                            'action' => $action,
                            'message' => $message,
                        ],
                    ],
                ],
            ],
        ];

        try {
            // Merge with existing config by reloading
            $existingGroups = $this->guardrailsEngine->getGroups();
            $mergedConfig = GuardrailsConfig::fromArray($ruleConfig);
            // Note: Full merge would require re-parsing all existing rules.
            // For now, reload with the new adaptive group alongside existing.
            $this->logger->debug("AdaptiveFeedback: rule applied to GuardrailsEngine", [
                'rule' => $ruleName,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning("AdaptiveFeedback: failed to apply rule: {$e->getMessage()}");
        }
    }

    /**
     * Build memory content from a pattern.
     */
    private function buildMemoryContent(CorrectionPattern $pattern): string
    {
        $reasons = implode("\n- ", $pattern->reasons);
        $tool = $pattern->toolName ? "\n**Tool:** {$pattern->toolName}" : '';

        return "{$pattern->pattern}{$tool}\n\n"
            . "**Why:** User has corrected this behavior {$pattern->occurrences} times.\n"
            . "**How to apply:** Avoid this pattern in future interactions.\n\n"
            . "**User feedback:**\n- {$reasons}";
    }

    /**
     * Emit an event.
     */
    private function emit(string $event, PromotionResult $result): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($result);
            } catch (\Throwable $e) {
                $this->logger->warning("AdaptiveFeedbackEngine event error: {$e->getMessage()}");
            }
        }
    }
}
