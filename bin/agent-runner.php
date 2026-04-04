#!/usr/bin/env php
<?php

/**
 * SuperAgent Process Runner
 *
 * Runs a single agent in a separate OS process. The parent sends a JSON
 * config blob on stdin (one line), then closes stdin. This script creates
 * a real Agent with full LLM provider, executes the prompt, and writes
 * the serialized AgentResult as a single JSON line on stdout.
 *
 * Exit codes:
 *   0 — success (result on stdout)
 *   1 — runtime error (message on stderr)
 */

// Ensure we can find the autoloader whether this script lives in
// the package's own bin/ or inside vendor/forgeomni/superagent/bin/.
$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];
foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
        break;
    }
}

use SuperAgent\Agent;
use SuperAgent\AgentResult;

// ── Read config from stdin (single JSON line) ──────────────────────
$stdinData = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 65536);
    if ($chunk === false) {
        break;
    }
    $stdinData .= $chunk;
}

$config = json_decode(trim($stdinData), true);
if (!$config || !isset($config['prompt'])) {
    fwrite(STDERR, "[agent-runner] Invalid or missing config on stdin\n");
    exit(1);
}

$prompt = $config['prompt'];
$agentConfig = $config['agent_config'] ?? [];

// Log startup
$agentId = $config['agent_id'] ?? 'unknown';
$agentName = $config['agent_name'] ?? 'agent';
fwrite(STDERR, "[agent-runner] Starting agent {$agentId} ({$agentName})\n");

try {
    // Create a real Agent with full provider + tools
    $agent = new Agent($agentConfig);

    // Run the prompt (blocking — this process exists solely for this)
    $result = $agent->run($prompt);

    // Serialize result to stdout
    $output = [
        'success' => true,
        'agent_id' => $agentId,
        'text' => $result->text(),
        'turns' => $result->turns(),
        'cost_usd' => $result->totalCostUsd,
        'usage' => [
            'input_tokens' => $result->totalUsage()->inputTokens,
            'output_tokens' => $result->totalUsage()->outputTokens,
        ],
        'responses' => array_map(function ($msg) {
            return [
                'text' => $msg->text(),
                'usage' => $msg->usage ? [
                    'input_tokens' => $msg->usage->inputTokens,
                    'output_tokens' => $msg->usage->outputTokens,
                ] : null,
                'stop_reason' => $msg->stopReason?->value,
            ];
        }, $result->allResponses),
    ];

    fwrite(STDOUT, json_encode($output, JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDERR, "[agent-runner] Agent {$agentId} completed ({$result->turns()} turns)\n");
    exit(0);

} catch (\Throwable $e) {
    $error = [
        'success' => false,
        'agent_id' => $agentId,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];

    fwrite(STDOUT, json_encode($error, JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDERR, "[agent-runner] Error: {$e->getMessage()}\n");
    exit(1);
}
