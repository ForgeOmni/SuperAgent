#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SmartFlow single-shot agent worker.
 *
 * Runs exactly ONE cross-model agent call in its own OS process so that a
 * SmartFlow `parallel()` / `pipeline()` batch achieves real wall-clock
 * concurrency ("true parallel via process pool"). The parent ({@see
 * \SuperAgent\SmartFlow\ProcessPool}) writes a single JSON blob on stdin:
 *
 *   {"call": {...AgentCall::toArray()...}, "fake": false,
 *    "default_provider": "openai", "default_model": null, "base_path": "/proj"}
 *
 * and reads one JSON line of {@see \SuperAgent\SmartFlow\AgentResult::toArray()}
 * back on stdout. This worker is deliberately tool-less and single-turn — unlike
 * bin/agent-runner.php it does not run the full agent loop.
 *
 * Exit codes: 0 = result on stdout, 1 = fatal (error JSON on stdout + stderr).
 */

$stdin = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 65536);
    if ($chunk === false) {
        break;
    }
    $stdin .= $chunk;
}

$payload = json_decode(trim($stdin), true);
if (!is_array($payload) || !isset($payload['call'])) {
    fwrite(STDERR, "[flow-agent-runner] invalid/missing payload on stdin\n");
    fwrite(STDOUT, json_encode(['skip' => true, 'layer' => 'none', 'valid' => false, 'error' => 'invalid payload']) . "\n");
    exit(1);
}

// ── Bootstrap: Laravel app OR standalone Application ──────────────
$basePath = $payload['base_path'] ?? null;
$bootstrapped = false;

$autoloaders = [
    ($basePath ?? __DIR__ . '/..') . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];
foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
        break;
    }
}

try {
    if ($basePath && file_exists($basePath . '/bootstrap/app.php')) {
        $app = require $basePath . '/bootstrap/app.php';
        if ($app instanceof \Illuminate\Contracts\Foundation\Application) {
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $bootstrapped = true;
        }
    }
} catch (\Throwable) {
    $bootstrapped = false;
}

if (!$bootstrapped && class_exists(\SuperAgent\Foundation\Application::class)) {
    try {
        \SuperAgent\Foundation\Application::bootstrap($basePath ?: getcwd());
    } catch (\Throwable) {
        // proceed — FlowAgentRunner reads creds defensively
    }
}

use SuperAgent\SmartFlow\AgentCall;
use SuperAgent\SmartFlow\FlowAgentRunner;
use SuperAgent\SmartFlow\PersonaRegistry;

try {
    $call = AgentCall::fromArray($payload['call']);
    $runner = new FlowAgentRunner(
        personas: PersonaRegistry::load(),
        fake: (bool) ($payload['fake'] ?? false),
        defaultProvider: $payload['default_provider'] ?? null,
        defaultModel: $payload['default_model'] ?? null,
    );
    $result = $runner->run($call);

    fwrite(STDOUT, json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[flow-agent-runner] ' . $e->getMessage() . "\n");
    fwrite(STDOUT, json_encode([
        'skip' => true,
        'layer' => 'none',
        'valid' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
