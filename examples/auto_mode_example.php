<?php

/**
 * SuperAgent Automatic Multi-Agent Mode Detection Example
 * 
 * This example demonstrates how SuperAgent automatically detects task complexity
 * and decides whether to use single-agent or multi-agent mode.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Agent;
use SuperAgent\AutoMode\TaskAnalyzer;

// Example 1: Simple task - will use single agent
echo "=== Example 1: Simple Task ===\n";

// Note: Agent creation requires API key, so we'll focus on task analysis
// In production, you would configure the agent with proper credentials:
// $agent = new Agent([
//     'auto_mode' => true,
//     'provider' => 'anthropic',
//     'api_key' => 'your-api-key',
// ]);

$simplePrompt = "What is the capital of France?";
echo "Prompt: $simplePrompt\n";

// Analyze the task first (for demonstration)
$analyzer = new TaskAnalyzer();
$analysis = $analyzer->analyzeTask($simplePrompt);
echo "Analysis: " . $analysis . "\n";
echo "Complexity Score: " . number_format($analysis->getComplexityScore(), 2) . "\n\n";

// The agent would automatically use single-agent mode
// $result = $agent->run($simplePrompt);

// Example 2: Complex task - will trigger multi-agent mode
echo "=== Example 2: Complex Task ===\n";

$complexPrompt = <<<PROMPT
Please help me with the following tasks:
1. Analyze the code in the src/ directory for potential security vulnerabilities
2. Generate a comprehensive report documenting all findings
3. Create unit tests for the identified vulnerabilities
4. Implement fixes for critical security issues
5. Update the documentation to reflect the changes
PROMPT;

echo "Prompt: " . substr($complexPrompt, 0, 100) . "...\n";

$analysis = $analyzer->analyzeTask($complexPrompt);
echo "Analysis: " . $analysis . "\n";
echo "Complexity Score: " . number_format($analysis->getComplexityScore(), 2) . "\n";

$metrics = $analysis->getMetrics();
echo "Detected Subtasks: " . $metrics['subtask_count'] . "\n";
echo "Estimated Tools Needed: " . $metrics['tool_count'] . "\n";
echo "Estimated Tokens: " . $metrics['token_estimate'] . "\n\n";

// Get suggested configuration
$suggestion = $analyzer->suggestConfiguration($analysis);
echo "Suggested Configuration:\n";
echo "  Mode: " . $suggestion['mode'] . "\n";
echo "  Number of Agents: " . $suggestion['agents'] . "\n";
if (isset($suggestion['team_structure'])) {
    echo "  Team Structure: " . implode(', ', $suggestion['team_structure']) . "\n";
}
echo "\n";

// Example 3: Custom threshold configuration
echo "=== Example 3: Custom Thresholds ===\n";

// Custom agent configuration (requires API key in production)
// $customAgent = new Agent([
//     'auto_mode' => true,
//     'api_key' => 'your-api-key',
//     'auto_mode_config' => [
//         'threshold' => [...],
//     ],
// ]);

$moderatePrompt = "Please review this code and write some tests for it.";
echo "Prompt: $moderatePrompt\n";

$customAnalyzer = new TaskAnalyzer([
    'threshold' => [
        'complexity_score' => 0.5,
        'min_subtasks' => 2,
        'min_tools' => 3,
    ],
]);

$analysis = $customAnalyzer->analyzeTask($moderatePrompt);
echo "Analysis: " . $analysis . "\n";
echo "With custom thresholds, this task would use: " . 
     ($analysis->shouldUseMultiAgent() ? "Multi-Agent Mode" : "Single-Agent Mode") . "\n\n";

// Example 4: Chinese language support
echo "=== Example 4: Chinese Language Task ===\n";

$chinesePrompt = <<<PROMPT
请帮我完成以下任务：
1. 分析代码库中的性能瓶颈
2. 生成优化建议报告
3. 实现关键性能优化
4. 创建性能测试套件
5. 更新技术文档
PROMPT;

echo "Prompt: " . substr($chinesePrompt, 0, 50) . "...\n";

$analysis = $analyzer->analyzeTask($chinesePrompt);
echo "Analysis: " . $analysis . "\n";
echo "Complexity Score: " . number_format($analysis->getComplexityScore(), 2) . "\n";
echo "Mode: " . ($analysis->shouldUseMultiAgent() ? "多智能体模式" : "单智能体模式") . "\n\n";

// Example 5: Fluent API usage
echo "=== Example 5: Fluent API ===\n";

// Fluent API configuration (requires API key in production)
// $fluentAgent = (new Agent(['api_key' => 'your-api-key']))
//     ->withAutoMode(true, [
//         'threshold' => [
//             'complexity_score' => 0.6,
//         ],
//         'multi_agent_config' => [
//             'max_agents' => 5,
//             'enable_display' => true,
//         ],
//     ])
//     ->withModel('claude-3-opus-20240229')
//     ->withMaxTurns(10);

echo "Agent can be configured with fluent API for auto-mode detection\n";

// Example 6: Programmatic task analysis
echo "\n=== Example 6: Programmatic Analysis ===\n";

$tasks = [
    "Calculate 5 + 3",
    "Write a hello world program",
    "Build a complete e-commerce website with user authentication, product catalog, shopping cart, payment processing, and admin dashboard",
    "Review code, fix bugs, add tests, update documentation, and deploy to production",
];

foreach ($tasks as $task) {
    $analysis = $analyzer->analyzeTask($task);
    $mode = $analysis->shouldUseMultiAgent() ? "Multi-Agent" : "Single-Agent";
    $score = number_format($analysis->getComplexityScore(), 2);
    
    printf("Task: %-50s | Mode: %-12s | Score: %s\n", 
           substr($task, 0, 50), 
           $mode, 
           $score);
}

echo "\n=== Configuration Guide ===\n";
echo <<<GUIDE
To enable automatic mode detection in your Laravel application:

1. Update your .env file:
   SUPERAGENT_AUTO_MODE=true
   SUPERAGENT_AUTO_MODE_COMPLEXITY=0.7
   SUPERAGENT_AUTO_MODE_MIN_SUBTASKS=3
   SUPERAGENT_AUTO_MODE_MIN_TOOLS=4
   SUPERAGENT_AUTO_MODE_MIN_TOKENS=10000

2. Or configure programmatically:
   \$agent = new Agent([
       'auto_mode' => true,
       'auto_mode_config' => [
           // your custom thresholds
       ],
   ]);

3. Or use the fluent API:
   \$agent->withAutoMode(true, \$config);

The system will automatically:
- Analyze task complexity
- Count subtasks and required tools
- Estimate token usage
- Choose the optimal execution mode
- Spawn appropriate number of agents for parallel execution

GUIDE;