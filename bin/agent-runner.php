#!/usr/bin/env php
<?php

/**
 * SuperAgent Process Runner
 *
 * Runs a single agent in a separate OS process. The parent sends a JSON
 * config blob on stdin (one line), then closes stdin. This script:
 *
 *   1. Bootstraps Laravel (if base_path is provided) so that config(),
 *      AgentManager, SkillManager, MCPManager, ExperimentalFeatures,
 *      and .claude/ directory loading all work identically to the parent.
 *   2. Creates a real SuperAgent\Agent with full LLM provider and tools.
 *   3. Executes the prompt and writes a JSON result line to stdout.
 *
 * Exit codes:
 *   0 — success (result on stdout)
 *   1 — runtime error (message on stderr)
 */

// ── Read config from stdin (single JSON blob) ─────────────────────
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

// ── Bootstrap: Laravel app OR plain Composer autoloader ───────────
$laravelBootstrapped = false;
$basePath = $config['base_path'] ?? null;

if ($basePath && file_exists($basePath . '/bootstrap/app.php')) {
    // Laravel project — full bootstrap gives us config(), base_path(),
    // service providers, AgentManager, SkillManager, MCPManager, etc.
    try {
        // The autoloader is loaded as part of the Laravel bootstrap
        require_once $basePath . '/vendor/autoload.php';

        $app = require_once $basePath . '/bootstrap/app.php';

        // Bootstrap the console kernel (loads config, service providers, etc.)
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        $laravelBootstrapped = true;
    } catch (\Throwable $e) {
        fwrite(STDERR, "[agent-runner] Laravel bootstrap failed: {$e->getMessage()}, falling back to autoloader\n");
    }
}

if (!$laravelBootstrapped) {
    // Fallback: plain Composer autoloader (no config(), no .claude/ loading)
    $autoloaders = [
        ($basePath ?? __DIR__ . '/..') . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
    ];
    foreach ($autoloaders as $autoloader) {
        if (file_exists($autoloader)) {
            require_once $autoloader;
            break;
        }
    }
}

use SuperAgent\Agent;

$prompt = $config['prompt'];
$agentConfig = $config['agent_config'] ?? [];

// Propagate working directory so .claude/ relative paths resolve correctly
if ($basePath && !isset($agentConfig['working_directory'])) {
    $agentConfig['working_directory'] = $basePath;
}

// ── Import parent's registrations ────────────────────────────────
// These allow the child process to access all agent definitions,
// skills, and MCP servers the parent had — without needing Laravel
// config or filesystem access to .claude/ directories.

// Agent definitions (builtin + custom from .claude/agents/)
if (!empty($config['agent_definitions'])) {
    try {
        \SuperAgent\Agent\AgentManager::getInstance()
            ->importDefinitions($config['agent_definitions']);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[agent-runner] Failed to import agent definitions: {$e->getMessage()}\n");
    }
}

// MCP server configurations
if (!empty($config['mcp_servers'])) {
    try {
        $mcpManager = \SuperAgent\MCP\MCPManager::getInstance();
        foreach ($config['mcp_servers'] as $name => $serverData) {
            $serverConfig = new \SuperAgent\MCP\Types\ServerConfig(
                name: $serverData['name'],
                type: $serverData['type'],
                config: $serverData['config'],
                enabled: $serverData['enabled'] ?? true,
            );
            $mcpManager->registerServer($serverConfig);
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[agent-runner] Failed to import MCP servers: {$e->getMessage()}\n");
    }
}

$agentId = $config['agent_id'] ?? 'unknown';
$agentName = $config['agent_name'] ?? 'agent';
$defCount = count($config['agent_definitions'] ?? []);
$mcpCount = count($config['mcp_servers'] ?? []);
fwrite(STDERR, "[agent-runner] Starting agent {$agentId} ({$agentName})"
    . ($laravelBootstrapped ? ' [Laravel]' : ' [standalone]')
    . " agents={$defCount} mcp={$mcpCount}\n");

try {
    $agent = new Agent($agentConfig);
    $result = $agent->run($prompt);

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
