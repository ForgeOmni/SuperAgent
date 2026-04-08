<?php

declare(strict_types=1);

namespace SuperAgent\Optimization;

/**
 * Routes queries to appropriate models based on content complexity analysis.
 *
 * Inspired by hermes-agent's smart_model_routing.py — detects "simple" queries
 * (no code, URLs, debugging keywords) and routes them to cheaper models,
 * while keeping complex tasks on the primary model.
 *
 * This complements the existing ModelRouter (which routes based on consecutive
 * tool-call turns) by analyzing the actual query content.
 */
class QueryComplexityRouter
{
    /**
     * Keywords that indicate a complex query requiring the primary model.
     */
    private const COMPLEXITY_KEYWORDS = [
        // Code-related
        'debug', 'error', 'exception', 'stack trace', 'traceback', 'bug',
        'fix', 'refactor', 'implement', 'architecture', 'design pattern',
        'optimize', 'performance', 'memory leak', 'race condition',

        // File operations
        'file', 'directory', 'path', 'read', 'write', 'edit', 'create',
        'delete', 'rename', 'move',

        // Analysis
        'analyze', 'review', 'audit', 'explain this code', 'how does this work',
        'security', 'vulnerability', 'test', 'spec',

        // Multi-step
        'step by step', 'multiple', 'several', 'then', 'after that',
        'first', 'second', 'finally',
    ];

    /**
     * Patterns that indicate code content.
     */
    private const CODE_PATTERNS = [
        '/```[\s\S]*?```/s',                         // Fenced code blocks
        '/\b(?:function|class|def|const|let|var)\s/i', // Declarations
        '/[{}\[\]();].*[{}\[\]();]/s',                // Multiple brackets
        '/(?:https?|ftp):\/\/\S+/i',                  // URLs
        '/\b\w+\.\w+\.\w+/i',                        // Dotted paths (file.ext.ext)
        '/\$\w+|\{\{.*?\}\}/i',                       // Variables/templates
        '/->|=>|::/i',                                 // Operators
    ];

    public function __construct(
        private bool $enabled = true,
        private string $primaryModel = '',
        private string $fastModel = 'claude-haiku-4-5-20251001',
        private int $maxSimpleChars = 200,
        private int $maxSimpleWords = 40,
        private int $maxSimpleNewlines = 2,
    ) {}

    public static function fromConfig(string $currentModel): self
    {
        try {
            $config = function_exists('config')
                ? (config('superagent.optimization.query_complexity_routing') ?? [])
                : [];
        } catch (\Throwable) {
            $config = [];
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            primaryModel: $currentModel,
            fastModel: $config['fast_model'] ?? 'claude-haiku-4-5-20251001',
            maxSimpleChars: (int) ($config['max_simple_chars'] ?? 200),
            maxSimpleWords: (int) ($config['max_simple_words'] ?? 40),
            maxSimpleNewlines: (int) ($config['max_simple_newlines'] ?? 2),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Analyze a user query and decide which model to use.
     *
     * @return string|null  Model to use, or null to use default (primary)
     */
    public function route(string $query): ?string
    {
        if (!$this->enabled || empty(trim($query))) {
            return null;
        }

        // Never downgrade if already on a cheap model
        if ($this->isAlreadyCheap()) {
            return null;
        }

        $analysis = $this->analyze($query);

        if ($analysis['is_simple']) {
            return $this->fastModel;
        }

        return null; // Use primary
    }

    /**
     * Analyze query complexity.
     *
     * @return array{is_simple: bool, reason: string, score: float}
     */
    public function analyze(string $query): array
    {
        $query = trim($query);
        $score = 0.0;
        $reasons = [];

        // Length checks
        $charCount = mb_strlen($query);
        $wordCount = str_word_count($query);
        $newlineCount = substr_count($query, "\n");

        if ($charCount > $this->maxSimpleChars) {
            $score += 0.3;
            $reasons[] = "long ({$charCount} chars)";
        }

        if ($wordCount > $this->maxSimpleWords) {
            $score += 0.2;
            $reasons[] = "many words ({$wordCount})";
        }

        if ($newlineCount > $this->maxSimpleNewlines) {
            $score += 0.2;
            $reasons[] = "multi-line ({$newlineCount} newlines)";
        }

        // Complexity keyword check
        $lowerQuery = strtolower($query);
        $keywordHits = 0;
        foreach (self::COMPLEXITY_KEYWORDS as $keyword) {
            if (str_contains($lowerQuery, $keyword)) {
                $keywordHits++;
            }
        }

        if ($keywordHits > 0) {
            $score += min(0.5, $keywordHits * 0.15);
            $reasons[] = "{$keywordHits} complexity keyword(s)";
        }

        // Code content check
        foreach (self::CODE_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                $score += 0.4;
                $reasons[] = 'contains code/URLs';
                break;
            }
        }

        // Question mark with simple phrasing (likely a simple question)
        if (str_contains($query, '?') && $wordCount <= 10 && $keywordHits === 0) {
            $score -= 0.3;
            $reasons[] = 'simple question';
        }

        $isSimple = $score < 0.3;

        return [
            'is_simple' => $isSimple,
            'reason' => $isSimple ? 'simple query' : implode(', ', $reasons),
            'score' => round($score, 2),
        ];
    }

    private function isAlreadyCheap(): bool
    {
        if ($this->primaryModel === $this->fastModel) {
            return true;
        }
        return str_contains(strtolower($this->primaryModel), 'haiku');
    }
}
