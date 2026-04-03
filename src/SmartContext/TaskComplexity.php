<?php

declare(strict_types=1);

namespace SuperAgent\SmartContext;

/**
 * Analyzes a user prompt to estimate task complexity.
 *
 * Uses heuristic scoring based on:
 *   - Prompt length (longer = more complex)
 *   - Complexity keywords (refactor, architect, debug, optimize, etc.)
 *   - Simplicity keywords (list, show, read, what is, etc.)
 *   - Multi-step indicators (then, after that, also, and then)
 *   - Code block presence
 *   - Question vs. instruction detection
 */
class TaskComplexity
{
    /** Keywords that suggest complex reasoning tasks. */
    private const COMPLEXITY_KEYWORDS = [
        'refactor', 'architect', 'design', 'optimize', 'debug',
        'investigate', 'analyze', 'migrate', 'implement', 'rewrite',
        'security audit', 'performance', 'race condition', 'deadlock',
        'concurrency', 'algorithm', 'data structure', 'trade-off',
        'why does', 'root cause', 'explain how', 'compare',
    ];

    /** Keywords that suggest simple/direct tasks. */
    private const SIMPLICITY_KEYWORDS = [
        'list', 'show', 'read', 'cat', 'print', 'display',
        'what is', 'where is', 'find', 'search', 'grep',
        'rename', 'move', 'copy', 'delete', 'create file',
        'add import', 'fix typo', 'update version',
    ];

    /** Keywords that suggest multi-step tasks. */
    private const MULTI_STEP_KEYWORDS = [
        'then', 'after that', 'also', 'and then', 'next',
        'step 1', 'step 2', 'first', 'second', 'finally',
        'additionally', 'moreover', 'furthermore',
    ];

    /**
     * @param float $score Complexity score (0.0 = trivial, 1.0 = maximum complexity)
     * @param ContextStrategy $strategy Recommended strategy
     * @param array $signals Detected signals for debugging
     */
    public function __construct(
        public readonly float $score,
        public readonly ContextStrategy $strategy,
        public readonly array $signals = [],
    ) {}

    /**
     * Analyze a prompt and return a complexity assessment.
     */
    public static function analyze(string $prompt): self
    {
        $prompt = strtolower($prompt);
        $score = 0.0;
        $signals = [];

        // Prompt length (longer prompts tend to be more complex)
        $length = strlen($prompt);
        if ($length > 2000) {
            $score += 0.2;
            $signals[] = 'long_prompt';
        } elseif ($length > 500) {
            $score += 0.1;
            $signals[] = 'medium_prompt';
        } elseif ($length < 100) {
            $score -= 0.1;
            $signals[] = 'short_prompt';
        }

        // Complexity keywords
        $complexityHits = 0;
        foreach (self::COMPLEXITY_KEYWORDS as $keyword) {
            if (str_contains($prompt, $keyword)) {
                $complexityHits++;
            }
        }
        if ($complexityHits >= 3) {
            $score += 0.3;
            $signals[] = "complex_keywords:{$complexityHits}";
        } elseif ($complexityHits >= 1) {
            $score += 0.15;
            $signals[] = "complex_keywords:{$complexityHits}";
        }

        // Simplicity keywords
        $simplicityHits = 0;
        foreach (self::SIMPLICITY_KEYWORDS as $keyword) {
            if (str_contains($prompt, $keyword)) {
                $simplicityHits++;
            }
        }
        if ($simplicityHits >= 2) {
            $score -= 0.2;
            $signals[] = "simple_keywords:{$simplicityHits}";
        } elseif ($simplicityHits >= 1) {
            $score -= 0.1;
            $signals[] = "simple_keywords:{$simplicityHits}";
        }

        // Multi-step indicators
        $multiStepHits = 0;
        foreach (self::MULTI_STEP_KEYWORDS as $keyword) {
            if (str_contains($prompt, $keyword)) {
                $multiStepHits++;
            }
        }
        if ($multiStepHits >= 2) {
            $score += 0.15;
            $signals[] = "multi_step:{$multiStepHits}";
        }

        // Code blocks suggest implementation work
        if (str_contains($prompt, '```') || str_contains($prompt, '<?php') || str_contains($prompt, 'function ')) {
            $score += 0.1;
            $signals[] = 'has_code';
        }

        // Questions are typically simpler than instructions
        if (str_ends_with(trim($prompt), '?') && $length < 200) {
            $score -= 0.1;
            $signals[] = 'short_question';
        }

        // Clamp to [0, 1]
        $score = max(0.0, min(1.0, $score + 0.5)); // 0.5 is the neutral baseline

        // Map to strategy
        $strategy = match (true) {
            $score >= 0.7 => ContextStrategy::DEEP_THINKING,
            $score <= 0.35 => ContextStrategy::BROAD_CONTEXT,
            default => ContextStrategy::BALANCED,
        };

        return new self($score, $strategy, $signals);
    }

    /**
     * Human-readable description.
     */
    public function describe(): string
    {
        $pct = round($this->score * 100);
        $signalStr = implode(', ', $this->signals);

        return "Complexity: {$pct}% → {$this->strategy->value} [{$signalStr}]";
    }
}
