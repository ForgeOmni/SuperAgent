<?php

declare(strict_types=1);

namespace SuperAgent\CostPrediction;

final class TaskAnalyzer
{
    private const TYPE_PATTERNS = [
        TaskProfile::TYPE_CODE_GENERATION => [
            'write', 'create', 'implement', 'build', 'generate', 'add.*function',
            'add.*class', 'add.*method', 'new.*file', 'scaffold',
        ],
        TaskProfile::TYPE_REFACTORING => [
            'refactor', 'restructure', 'reorganize', 'rename', 'extract', 'inline',
            'move.*to', 'split.*into', 'merge', 'clean.*up', 'simplify',
        ],
        TaskProfile::TYPE_TESTING => [
            'test', 'spec', 'assert', 'coverage', 'unit test', 'integration test',
            'e2e', 'mock', 'fixture',
        ],
        TaskProfile::TYPE_DEBUGGING => [
            'fix', 'bug', 'error', 'issue', 'broken', 'crash', 'fail', 'debug',
            'not working', 'doesn\'t work', 'investigate', 'diagnose',
        ],
        TaskProfile::TYPE_ANALYSIS => [
            'explain', 'analyze', 'review', 'understand', 'how does', 'what does',
            'describe', 'document', 'audit', 'assess',
        ],
        TaskProfile::TYPE_MULTI_FILE => [
            'all files', 'every file', 'across.*codebase', 'entire project',
            'batch', 'multiple files', 'everywhere',
        ],
    ];

    private const COMPLEXITY_INDICATORS = [
        'very_complex' => [
            'entire codebase', 'all files', 'complete rewrite', 'from scratch',
            'migrate', 'architecture', 'distributed', 'across.*services',
        ],
        'complex' => [
            'refactor all', 'multiple files', 'integrate', 'pipeline',
            'workflow', 'system', 'comprehensive', 'full',
        ],
        'moderate' => [
            'add feature', 'update', 'modify', 'change', 'extend', 'enhance',
            'improve', 'optimize',
        ],
        'simple' => [
            'rename', 'typo', 'comment', 'format', 'small', 'quick', 'simple',
            'one line', 'trivial',
        ],
    ];

    private const TOOL_INDICATORS = [
        'read' => ['read', 'look at', 'check', 'see', 'show me', 'view'],
        'edit' => ['edit', 'change', 'modify', 'update', 'replace'],
        'write' => ['write', 'create', 'new file', 'generate'],
        'bash' => ['run', 'execute', 'install', 'build', 'compile', 'npm', 'composer'],
        'grep' => ['find', 'search', 'where is', 'locate', 'grep'],
        'glob' => ['list', 'files', 'directory', 'pattern'],
        'web_search' => ['search online', 'look up', 'documentation', 'how to'],
    ];

    /**
     * Analyze a prompt to determine task profile.
     */
    public function analyze(string $prompt): TaskProfile
    {
        $lowerPrompt = mb_strtolower($prompt);
        $promptLength = mb_strlen($prompt);
        $wordCount = str_word_count($prompt);

        $taskType = $this->detectTaskType($lowerPrompt);
        $complexity = $this->detectComplexity($lowerPrompt, $promptLength);
        $likelyTools = $this->detectLikelyTools($lowerPrompt);
        $estimatedToolCalls = $this->estimateToolCalls($complexity, $taskType, $likelyTools);
        $estimatedTurns = $this->estimateTurns($complexity, $taskType);
        $estimatedInputTokens = $this->estimateInputTokens($complexity, $estimatedTurns, $promptLength);
        $estimatedOutputTokens = $this->estimateOutputTokens($complexity, $taskType, $estimatedTurns);

        $taskHash = md5($taskType . ':' . $complexity . ':' . implode(',', $likelyTools));

        return new TaskProfile(
            taskType: $taskType,
            complexity: $complexity,
            estimatedToolCalls: $estimatedToolCalls,
            likelyTools: $likelyTools,
            estimatedTurns: $estimatedTurns,
            estimatedInputTokens: $estimatedInputTokens,
            estimatedOutputTokens: $estimatedOutputTokens,
            taskHash: $taskHash,
        );
    }

    private function detectTaskType(string $prompt): string
    {
        $scores = [];

        foreach (self::TYPE_PATTERNS as $type => $patterns) {
            $score = 0;
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $prompt)) {
                    $score++;
                }
            }
            $scores[$type] = $score;
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? $best : TaskProfile::TYPE_CHAT;
    }

    private function detectComplexity(string $prompt, int $promptLength): string
    {
        // Check indicator patterns
        foreach (self::COMPLEXITY_INDICATORS as $level => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match('/' . $pattern . '/i', $prompt)) {
                    return match ($level) {
                        'very_complex' => TaskProfile::COMPLEXITY_VERY_COMPLEX,
                        'complex' => TaskProfile::COMPLEXITY_COMPLEX,
                        'moderate' => TaskProfile::COMPLEXITY_MODERATE,
                        'simple' => TaskProfile::COMPLEXITY_SIMPLE,
                        default => TaskProfile::COMPLEXITY_MODERATE,
                    };
                }
            }
        }

        // Fallback: use prompt length as heuristic
        if ($promptLength > 2000) {
            return TaskProfile::COMPLEXITY_COMPLEX;
        }
        if ($promptLength > 500) {
            return TaskProfile::COMPLEXITY_MODERATE;
        }
        return TaskProfile::COMPLEXITY_SIMPLE;
    }

    private function detectLikelyTools(string $prompt): array
    {
        $tools = [];
        foreach (self::TOOL_INDICATORS as $tool => $indicators) {
            foreach ($indicators as $indicator) {
                if (str_contains($prompt, $indicator)) {
                    $tools[] = $tool;
                    break;
                }
            }
        }

        // Always assume read for non-chat tasks
        if (!in_array('read', $tools) && !empty($tools)) {
            array_unshift($tools, 'read');
        }

        return array_unique($tools);
    }

    private function estimateToolCalls(string $complexity, string $taskType, array $tools): int
    {
        $base = match ($complexity) {
            TaskProfile::COMPLEXITY_SIMPLE => 2,
            TaskProfile::COMPLEXITY_MODERATE => 5,
            TaskProfile::COMPLEXITY_COMPLEX => 12,
            TaskProfile::COMPLEXITY_VERY_COMPLEX => 25,
            default => 5,
        };

        // Task type multipliers
        $multiplier = match ($taskType) {
            TaskProfile::TYPE_MULTI_FILE => 3.0,
            TaskProfile::TYPE_REFACTORING => 1.5,
            TaskProfile::TYPE_CODE_GENERATION => 1.3,
            TaskProfile::TYPE_TESTING => 1.2,
            TaskProfile::TYPE_DEBUGGING => 1.4,
            TaskProfile::TYPE_ANALYSIS => 0.8,
            TaskProfile::TYPE_CHAT => 0.3,
            default => 1.0,
        };

        return (int) round($base * $multiplier);
    }

    private function estimateTurns(string $complexity, string $taskType): int
    {
        $base = match ($complexity) {
            TaskProfile::COMPLEXITY_SIMPLE => 1,
            TaskProfile::COMPLEXITY_MODERATE => 3,
            TaskProfile::COMPLEXITY_COMPLEX => 8,
            TaskProfile::COMPLEXITY_VERY_COMPLEX => 15,
            default => 3,
        };

        if ($taskType === TaskProfile::TYPE_CHAT || $taskType === TaskProfile::TYPE_ANALYSIS) {
            return max(1, (int) ($base * 0.5));
        }

        return $base;
    }

    private function estimateInputTokens(string $complexity, int $turns, int $promptLength): int
    {
        // Base tokens: prompt + system prompt + tool schemas
        $baseTokens = (int) ($promptLength / 4) + 2000; // ~4 chars per token

        // Each turn accumulates context
        $perTurnGrowth = match ($complexity) {
            TaskProfile::COMPLEXITY_SIMPLE => 500,
            TaskProfile::COMPLEXITY_MODERATE => 1500,
            TaskProfile::COMPLEXITY_COMPLEX => 3000,
            TaskProfile::COMPLEXITY_VERY_COMPLEX => 5000,
            default => 1500,
        };

        // Sum of arithmetic series: base + (base + growth) + (base + 2*growth) + ...
        $total = 0;
        for ($i = 0; $i < $turns; $i++) {
            $total += $baseTokens + ($i * $perTurnGrowth);
        }

        return $total;
    }

    private function estimateOutputTokens(string $complexity, string $taskType, int $turns): int
    {
        $perTurn = match ($complexity) {
            TaskProfile::COMPLEXITY_SIMPLE => 300,
            TaskProfile::COMPLEXITY_MODERATE => 800,
            TaskProfile::COMPLEXITY_COMPLEX => 1500,
            TaskProfile::COMPLEXITY_VERY_COMPLEX => 2500,
            default => 800,
        };

        // Code generation produces more output
        if ($taskType === TaskProfile::TYPE_CODE_GENERATION || $taskType === TaskProfile::TYPE_TESTING) {
            $perTurn = (int) ($perTurn * 1.5);
        }

        return $perTurn * $turns;
    }
}
