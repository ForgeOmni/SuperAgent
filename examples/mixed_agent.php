<?php

declare(strict_types=1);

/**
 * Mixed-provider agent — Claude as the main brain, GLM web search, Qwen
 * thinking mode, MiniMax text-to-speech — all composed through the same
 * Tool and `features` interfaces introduced in v0.8.8.
 *
 * Run with whichever providers you have keys for. Each section is guarded
 * so missing credentials degrade to a warning instead of crashing.
 *
 * Usage:
 *   ANTHROPIC_API_KEY=...  \
 *   GLM_API_KEY=...        \
 *   QWEN_API_KEY=...       \
 *   MINIMAX_API_KEY=...    \
 *   php examples/mixed_agent.php "Summarise the latest PHP 8.3 release"
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SuperAgent\Providers\CapabilityRouter;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Tools\Providers\Glm\GlmWebReaderTool;
use SuperAgent\Tools\Providers\Glm\GlmWebSearchTool;
use SuperAgent\Tools\Providers\MiniMax\MiniMaxTtsTool;

$prompt = $argv[1] ?? 'Summarise the latest PHP 8.3 release and tell me in audio';

// ─── 1. Pick a main brain ─────────────────────────────────────────────────

// The main brain can be anyone — here we prefer Anthropic because it handles
// tool calls with minimal fuss. Swap 'anthropic' for 'openai' / 'gemini' /
// 'kimi' / 'qwen' / 'glm' / 'minimax' without changing the rest of the file.
if (! getenv('ANTHROPIC_API_KEY') && ! getenv('OPENAI_API_KEY')) {
    fwrite(STDERR, "No ANTHROPIC_API_KEY or OPENAI_API_KEY set; aborting.\n");
    exit(1);
}

$mainProviderName = getenv('ANTHROPIC_API_KEY') ? 'anthropic' : 'openai';
$main = ProviderRegistry::createFromEnv($mainProviderName);

printf("Main brain: %s (model: %s)\n", $main->name(), $main->getModel());

// ─── 2. Build the specialty toolbox ──────────────────────────────────────

// Each specialty tool reuses the vendor's already-authenticated Guzzle
// client. Main brain doesn't need to speak that vendor's API — the tool
// handles it transparently.

$tools = [];
$warnings = [];

if (getenv('GLM_API_KEY') || getenv('ZAI_API_KEY')) {
    $glm = ProviderRegistry::createFromEnv('glm');
    $tools[] = new GlmWebSearchTool($glm);
    $tools[] = new GlmWebReaderTool($glm);
    echo "Loaded GLM tools: web_search, web_reader\n";
} else {
    $warnings[] = 'GLM tools skipped (no GLM_API_KEY)';
}

if (getenv('MINIMAX_API_KEY')) {
    $mm = ProviderRegistry::createFromEnv('minimax');
    $tools[] = new MiniMaxTtsTool($mm);
    echo "Loaded MiniMax tool: tts\n";
} else {
    $warnings[] = 'MiniMax TTS skipped (no MINIMAX_API_KEY)';
}

if ($warnings !== []) {
    echo "\nWarnings:\n  - " . implode("\n  - ", $warnings) . "\n";
}

// ─── 3. Capability routing demo (optional) ───────────────────────────────

// Ask the CapabilityRouter what it would choose for a `thinking` request.
// This is pure metadata — it doesn't send anything.
try {
    $decision = CapabilityRouter::pick([
        'features' => ['thinking' => ['budget' => 2000]],
        'preferred' => ['anthropic', 'qwen', 'glm'],
    ]);
    printf(
        "\nCapabilityRouter would route `thinking` to: %s / %s%s\n",
        $decision->provider,
        $decision->model,
        $decision->region ? " ({$decision->region})" : '',
    );
} catch (\Throwable $e) {
    echo "\nCapabilityRouter: " . $e->getMessage() . "\n";
}

// ─── 4. Run the agent loop ───────────────────────────────────────────────

echo "\n--- Chat ---\n";
echo "User: {$prompt}\n\n";

$messages = [
    new \SuperAgent\Messages\UserMessage($prompt),
];

$systemPrompt = <<<'SYS'
You are a helpful assistant with access to web search, web reading, and
text-to-speech tools. When the user asks for "audio" or "read aloud",
finish by calling the TTS tool on the final answer.
SYS;

try {
    foreach ($main->chat($messages, $tools, $systemPrompt) as $chunk) {
        foreach ($chunk->content as $block) {
            if ($block->type === 'text') {
                echo $block->text;
            } elseif ($block->type === 'tool_use') {
                printf("\n[tool_use] %s %s\n", $block->name, json_encode($block->input));
            }
        }
    }
    echo "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "\nchat failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
