<?php

declare(strict_types=1);

namespace SuperAgent\AutoMode;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Analyzes task complexity to determine if multi-agent mode should be used.
 */
class TaskAnalyzer
{
    private LoggerInterface $logger;
    private array $config;
    
    // Keywords that suggest complex tasks
    private const COMPLEX_KEYWORDS = [
        'analyze', 'review', 'refactor', 'optimize', 'test', 'document',
        'generate', 'create', 'build', 'implement', 'design', 'architect',
        'debug', 'fix', 'resolve', 'investigate', 'research', 'compare',
        'migrate', 'deploy', 'configure', 'setup', 'install', 'update',
        '分析', '审查', '重构', '优化', '测试', '文档', '生成', '创建',
        '构建', '实现', '设计', '架构', '调试', '修复', '解决', '调查',
        '研究', '比较', '迁移', '部署', '配置', '安装', '更新'
    ];
    
    // Patterns that suggest multiple subtasks
    private const SUBTASK_PATTERNS = [
        '/\band\b/i',
        '/\bthen\b/i',
        '/\bafter\b/i',
        '/\bfinally\b/i',
        '/\balso\b/i',
        '/\badditionally\b/i',
        '/\bfurthermore\b/i',
        '/\d+\.\s+/m', // Numbered lists
        '/[-*]\s+/m',  // Bullet points
        '/，/u',       // Chinese comma
        '/并且/u',     // Chinese "and"
        '/然后/u',     // Chinese "then"
        '/最后/u',     // Chinese "finally"
        '/另外/u',     // Chinese "additionally"
    ];
    
    // Tool categories that might be needed
    private const TOOL_CATEGORIES = [
        'file' => ['read', 'write', 'edit', 'create', 'delete', 'copy', 'move'],
        'code' => ['analyze', 'refactor', 'lint', 'format', 'compile', 'build'],
        'test' => ['test', 'unit', 'integration', 'e2e', 'coverage', 'benchmark'],
        'web' => ['fetch', 'scrape', 'api', 'request', 'download', 'search'],
        'data' => ['parse', 'transform', 'aggregate', 'filter', 'sort', 'query'],
        'git' => ['commit', 'push', 'pull', 'merge', 'branch', 'tag', 'diff'],
    ];
    
    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->config = array_merge([
            'enabled' => true,
            'threshold' => [
                'complexity_score' => 0.7,
                'min_subtasks' => 3,
                'min_tools' => 4,
                'estimated_tokens' => 10000,
            ],
            'weights' => [
                'length' => 0.15,
                'keywords' => 0.25,
                'subtasks' => 0.30,
                'tools' => 0.20,
                'tokens' => 0.10,
            ],
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
    }
    
    /**
     * Analyze a task prompt to determine if multi-agent mode should be used.
     */
    public function analyzeTask(string $prompt): TaskAnalysisResult
    {
        if (!$this->config['enabled']) {
            return new TaskAnalysisResult(
                useMultiAgent: false,
                reason: 'Auto-mode detection is disabled',
                score: 0.0
            );
        }
        
        $this->logger->debug("Analyzing task for auto-mode detection", [
            'prompt_length' => strlen($prompt),
        ]);
        
        // Calculate individual metrics
        $lengthScore = $this->calculateLengthScore($prompt);
        $keywordScore = $this->calculateKeywordScore($prompt);
        $subtaskCount = $this->detectSubtasks($prompt);
        $subtaskScore = $this->calculateSubtaskScore($subtaskCount);
        $toolCount = $this->estimateToolCount($prompt);
        $toolScore = $this->calculateToolScore($toolCount);
        $tokenEstimate = $this->estimateTokens($prompt);
        $tokenScore = $this->calculateTokenScore($tokenEstimate);
        
        // Calculate weighted complexity score
        $weights = $this->config['weights'];
        $complexityScore = 
            $lengthScore * $weights['length'] +
            $keywordScore * $weights['keywords'] +
            $subtaskScore * $weights['subtasks'] +
            $toolScore * $weights['tools'] +
            $tokenScore * $weights['tokens'];
        
        // Determine if multi-agent mode should be used
        $threshold = $this->config['threshold'];
        $useMultiAgent = 
            $complexityScore >= $threshold['complexity_score'] ||
            $subtaskCount >= $threshold['min_subtasks'] ||
            $toolCount >= $threshold['min_tools'] ||
            $tokenEstimate >= ($threshold['estimated_tokens'] ?? 10000);
        
        // Build reason
        $reasons = [];
        if ($complexityScore >= $threshold['complexity_score']) {
            $reasons[] = sprintf('High complexity score (%.2f)', $complexityScore);
        }
        if ($subtaskCount >= $threshold['min_subtasks']) {
            $reasons[] = sprintf('Multiple subtasks detected (%d)', $subtaskCount);
        }
        if ($toolCount >= $threshold['min_tools']) {
            $reasons[] = sprintf('Multiple tool categories needed (%d)', $toolCount);
        }
        if ($tokenEstimate >= ($threshold['estimated_tokens'] ?? 10000)) {
            $reasons[] = sprintf('High token estimate (%d)', $tokenEstimate);
        }
        
        $reason = $useMultiAgent 
            ? 'Multi-agent mode triggered: ' . implode(', ', $reasons)
            : 'Single agent sufficient for this task';
        
        $this->logger->info("Task analysis complete", [
            'use_multi_agent' => $useMultiAgent,
            'complexity_score' => $complexityScore,
            'subtask_count' => $subtaskCount,
            'tool_count' => $toolCount,
            'token_estimate' => $tokenEstimate,
            'reason' => $reason,
        ]);
        
        return new TaskAnalysisResult(
            useMultiAgent: $useMultiAgent,
            reason: $reason,
            score: $complexityScore,
            metrics: [
                'length_score' => $lengthScore,
                'keyword_score' => $keywordScore,
                'subtask_count' => $subtaskCount,
                'subtask_score' => $subtaskScore,
                'tool_count' => $toolCount,
                'tool_score' => $toolScore,
                'token_estimate' => $tokenEstimate,
                'token_score' => $tokenScore,
            ]
        );
    }
    
    /**
     * Calculate score based on prompt length.
     */
    private function calculateLengthScore(string $prompt): float
    {
        $length = strlen($prompt);
        
        // Normalize length to 0-1 scale
        // < 100 chars = 0, > 1000 chars = 1
        if ($length < 100) {
            return 0.0;
        } elseif ($length > 1000) {
            return 1.0;
        }
        
        return ($length - 100) / 900;
    }
    
    /**
     * Calculate score based on presence of complex keywords.
     */
    private function calculateKeywordScore(string $prompt): float
    {
        $lowerPrompt = mb_strtolower($prompt);
        $keywordCount = 0;
        
        foreach (self::COMPLEX_KEYWORDS as $keyword) {
            if (mb_strpos($lowerPrompt, mb_strtolower($keyword)) !== false) {
                $keywordCount++;
            }
        }
        
        // Normalize to 0-1 scale
        // 0 keywords = 0, 5+ keywords = 1
        return min(1.0, $keywordCount / 5);
    }
    
    /**
     * Detect number of subtasks in the prompt.
     */
    private function detectSubtasks(string $prompt): int
    {
        $subtaskCount = 1; // At least one task
        
        // Check for subtask patterns
        foreach (self::SUBTASK_PATTERNS as $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $prompt, $matches)) {
                $subtaskCount += count($matches[0]);
            }
        }
        
        // Check for explicit task enumeration
        if (preg_match_all('/\b(?:first|second|third|fourth|fifth|then|next|finally)\b/i', $prompt, $matches)) {
            $subtaskCount = max($subtaskCount, count($matches[0]));
        }
        
        // Check for Chinese enumeration
        if (preg_match_all('/(?:第[一二三四五六七八九十]|首先|其次|然后|接着|最后)/u', $prompt, $matches)) {
            $subtaskCount = max($subtaskCount, count($matches[0]));
        }
        
        return $subtaskCount;
    }
    
    /**
     * Calculate score based on number of subtasks.
     */
    private function calculateSubtaskScore(int $subtaskCount): float
    {
        // Normalize to 0-1 scale
        // 1 subtask = 0, 5+ subtasks = 1
        if ($subtaskCount <= 1) {
            return 0.0;
        } elseif ($subtaskCount >= 5) {
            return 1.0;
        }
        
        return ($subtaskCount - 1) / 4;
    }
    
    /**
     * Estimate number of tool categories needed.
     */
    private function estimateToolCount(string $prompt): int
    {
        $lowerPrompt = mb_strtolower($prompt);
        $toolCategories = [];
        
        foreach (self::TOOL_CATEGORIES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($lowerPrompt, $keyword) !== false) {
                    $toolCategories[$category] = true;
                    break;
                }
            }
        }
        
        return count($toolCategories);
    }
    
    /**
     * Calculate score based on number of tools needed.
     */
    private function calculateToolScore(int $toolCount): float
    {
        // Normalize to 0-1 scale
        // 0 tools = 0, 4+ tools = 1
        if ($toolCount == 0) {
            return 0.0;
        } elseif ($toolCount >= 4) {
            return 1.0;
        }
        
        return $toolCount / 4;
    }
    
    /**
     * Estimate token count for the task.
     */
    private function estimateTokens(string $prompt): int
    {
        // Rough estimation: 1 token per 4 characters
        $promptTokens = (int) (strlen($prompt) / 4);
        
        // Estimate based on complexity indicators
        $multiplier = 1;
        
        // Long tasks likely need more context
        if (strlen($prompt) > 500) {
            $multiplier *= 2;
        }
        
        // Multiple subtasks mean multiple responses
        $subtaskCount = $this->detectSubtasks($prompt);
        if ($subtaskCount > 1) {
            $multiplier *= $subtaskCount;
        }
        
        // Complex operations need more tokens
        $keywordScore = $this->calculateKeywordScore($prompt);
        if ($keywordScore > 0.5) {
            $multiplier *= 2;
        }
        
        // Base response estimate: 500 tokens per simple response
        return $promptTokens + (500 * $multiplier);
    }
    
    /**
     * Calculate score based on estimated tokens.
     */
    private function calculateTokenScore(int $tokenEstimate): float
    {
        // Normalize to 0-1 scale
        // < 1000 tokens = 0, > 10000 tokens = 1
        if ($tokenEstimate < 1000) {
            return 0.0;
        } elseif ($tokenEstimate > 10000) {
            return 1.0;
        }
        
        return ($tokenEstimate - 1000) / 9000;
    }
    
    /**
     * Get suggested agent configuration based on analysis.
     */
    public function suggestConfiguration(TaskAnalysisResult $result): array
    {
        if (!$result->shouldUseMultiAgent()) {
            return [
                'mode' => 'single',
                'agents' => 1,
            ];
        }
        
        $metrics = $result->getMetrics();
        $subtaskCount = $metrics['subtask_count'] ?? 1;
        $toolCount = $metrics['tool_count'] ?? 1;
        
        // Suggest number of agents based on subtasks and tools
        $suggestedAgents = max(2, min(10, max($subtaskCount, $toolCount)));
        
        // Suggest team structure
        $teamStructure = [];
        if ($subtaskCount > 3) {
            $teamStructure[] = 'coordinator';
        }
        if ($toolCount > 0) {
            $teamStructure[] = 'specialists';
        }
        if ($metrics['token_estimate'] > 5000) {
            $teamStructure[] = 'parallel_workers';
        }
        
        return [
            'mode' => 'multi',
            'agents' => $suggestedAgents,
            'team_structure' => $teamStructure,
            'reason' => $result->getReason(),
        ];
    }
}