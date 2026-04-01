<?php

require __DIR__ . '/vendor/autoload.php';

use SuperAgent\Agent;
use SuperAgent\Tools\ClosureTool;
use SuperAgent\Tools\ToolResult;

// --- 配置 ---
$apiKey = getenv('ANTHROPIC_API_KEY') ?: throw new RuntimeException(
    '请先设置环境变量: export ANTHROPIC_API_KEY=sk-ant-xxx'
);

// 定义一个天气工具
$weatherTool = new ClosureTool(
    toolName: 'get_weather',
    toolDescription: 'Get the current weather for a given city.',
    toolInputSchema: [
        'type' => 'object',
        'properties' => [
            'city' => [
                'type' => 'string',
                'description' => 'The city name, e.g. "Beijing"',
            ],
        ],
        'required' => ['city'],
    ],
    handler: function (array $input): ToolResult {
        $city = $input['city'] ?? 'unknown';
        // 模拟返回
        return ToolResult::success("Weather in {$city}: Sunny, 24°C, humidity 45%");
    },
    readOnly: true,
);

// 定义一个计算工具
$calcTool = new ClosureTool(
    toolName: 'calculate',
    toolDescription: 'Evaluate a math expression and return the result.',
    toolInputSchema: [
        'type' => 'object',
        'properties' => [
            'expression' => [
                'type' => 'string',
                'description' => 'A math expression, e.g. "2 + 3 * 4"',
            ],
        ],
        'required' => ['expression'],
    ],
    handler: function (array $input): ToolResult {
        $expr = $input['expression'] ?? '';
        // 安全计算（仅允许数字和运算符）
        if (preg_match('/^[\d\s\+\-\*\/\.\(\)]+$/', $expr)) {
            $result = eval("return {$expr};");
            return ToolResult::success("Result: {$result}");
        }
        return ToolResult::error("Invalid expression: {$expr}");
    },
);

// --- 测试 1: 简单对话 ---
echo "=== Test 1: Simple Chat ===\n";

$agent = new Agent([
    'api_key' => $apiKey,
    'model' => 'claude-sonnet-4-20250514',
]);

$result = $agent->prompt('What is 2+2? Reply in one sentence.');
echo "Response: " . $result->text() . "\n";
echo "Tokens: input={$result->totalUsage()->inputTokens}, output={$result->totalUsage()->outputTokens}\n\n";

// --- 测试 2: 工具调用 ---
echo "=== Test 2: Tool Use ===\n";

$agent = new Agent([
    'api_key' => $apiKey,
    'model' => 'claude-sonnet-4-20250514',
    'tools' => [$weatherTool, $calcTool],
]);

$result = $agent->prompt('What is the weather in Tokyo? Also calculate 123 * 456 for me.');
echo "Response: " . $result->text() . "\n";
echo "Turns: " . $result->turns() . "\n";
echo "Tokens: input={$result->totalUsage()->inputTokens}, output={$result->totalUsage()->outputTokens}\n\n";

// --- 测试 3: 多轮对话 ---
echo "=== Test 3: Multi-turn ===\n";

$agent = new Agent([
    'api_key' => $apiKey,
    'model' => 'claude-sonnet-4-20250514',
]);

$r1 = $agent->prompt('My name is Xiyang. Remember it.');
echo "Turn 1: " . $r1->text() . "\n";

$r2 = $agent->prompt('What is my name?');
echo "Turn 2: " . $r2->text() . "\n";
echo "Total messages in history: " . count($agent->getMessages()) . "\n\n";

echo "=== All tests passed! ===\n";
