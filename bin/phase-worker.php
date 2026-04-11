#!/usr/bin/env php
<?php

/**
 * Out-of-process phase worker for CollaborationPipeline.
 *
 * Receives a phase configuration via stdin (JSON), executes all agents
 * within the phase sequentially (each child process handles one agent),
 * and outputs results as JSON on stdout.
 *
 * Usage:
 *   echo '{"phase":"research","agents":[...]}' | php bin/phase-worker.php
 *
 * Input format (stdin, single-line JSON):
 * {
 *   "phase": "phase_name",
 *   "agents": [
 *     {
 *       "name": "agent-1",
 *       "prompt": "...",
 *       "model": "claude-sonnet-4-6",
 *       "system_prompt": "...",
 *       "provider_config": { "provider": "anthropic", "api_key": "..." }
 *     }
 *   ],
 *   "base_path": "/path/to/laravel/app",
 *   "timeout": 300
 * }
 *
 * Output format (stdout, JSON):
 * {
 *   "phase": "phase_name",
 *   "status": "completed|failed",
 *   "results": {
 *     "agent-1": { "text": "...", "turns": 3, "cost_usd": 0.0123 }
 *   },
 *   "duration_ms": 1234.56,
 *   "error": null
 * }
 *
 * Progress events are emitted on stderr as NDJSON.
 */

declare(strict_types=1);

// --- Bootstrap ---

$basePath = null;

// Read config from stdin
$stdinLine = '';
$stdin = fopen('php://stdin', 'r');
if ($stdin) {
    stream_set_blocking($stdin, true);
    $stdinLine = trim(fgets($stdin) ?: '');
    fclose($stdin);
}

if ($stdinLine === '') {
    fwrite(STDERR, json_encode(['type' => 'error', 'message' => 'No input received on stdin']) . "\n");
    exit(1);
}

$config = json_decode($stdinLine, true);
if (!is_array($config)) {
    fwrite(STDERR, json_encode(['type' => 'error', 'message' => 'Invalid JSON input']) . "\n");
    exit(1);
}

$basePath = $config['base_path'] ?? null;

// Try Laravel bootstrap
$bootstrapped = false;
if ($basePath && file_exists($basePath . '/bootstrap/app.php')) {
    try {
        $app = require $basePath . '/bootstrap/app.php';
        if (method_exists($app, 'make')) {
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            $bootstrapped = true;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, json_encode([
            'type' => 'warning',
            'message' => 'Laravel bootstrap failed: ' . $e->getMessage(),
        ]) . "\n");
    }
}

// Fallback to composer autoloader
if (!$bootstrapped) {
    $autoloadPaths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ];
    if ($basePath) {
        array_unshift($autoloadPaths, $basePath . '/vendor/autoload.php');
    }
    foreach ($autoloadPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
}

// --- Execute phase ---

use SuperAgent\Agent;
use SuperAgent\AgentResult;
use SuperAgent\Coordinator\AgentRetryPolicy;
use SuperAgent\Providers\CredentialPool;

$phaseName = $config['phase'] ?? 'unknown';
$agents = $config['agents'] ?? [];
$timeout = $config['timeout'] ?? 300;

// Build retry policy from config
$retryConfig = $config['retry_policy'] ?? [];
$retryPolicy = new AgentRetryPolicy(
    maxAttempts: $retryConfig['max_attempts'] ?? 3,
    backoffType: $retryConfig['backoff_type'] ?? 'exponential',
    baseDelayMs: $retryConfig['base_delay_ms'] ?? 1000,
    maxDelayMs: $retryConfig['max_delay_ms'] ?? 30000,
    jitter: $retryConfig['jitter'] ?? true,
    rotateCredentialOnRateLimit: $retryConfig['rotate_credential_on_rate_limit'] ?? true,
    switchProviderOnFailure: $retryConfig['switch_provider_on_failure'] ?? false,
);

// Build credential pool if provided
$credentialPool = null;
if (!empty($config['credential_pool'])) {
    $credentialPool = new CredentialPool();
    foreach ($config['credential_pool'] as $provider => $poolConfig) {
        $strategy = $poolConfig['strategy'] ?? 'round_robin';
        foreach ($poolConfig['keys'] ?? [] as $key) {
            $credentialPool->addCredential($provider, $key, $strategy);
        }
    }
}

$startTime = microtime(true);
$results = [];
$overallStatus = 'completed';
$error = null;

fwrite(STDERR, json_encode([
    'type' => 'phase_start',
    'phase' => $phaseName,
    'agent_count' => count($agents),
    'timestamp' => $startTime,
]) . "\n");

/**
 * Execute a single agent with retry, credential rotation, and provider fallback.
 */
function executeAgentWithRetry(
    array $agentConfig,
    AgentRetryPolicy $retryPolicy,
    ?CredentialPool $credentialPool,
    string $phaseName,
): array {
    $agentName = $agentConfig['name'] ?? 'unnamed';
    $providerConfig = $agentConfig['provider_config'] ?? [];
    $providerName = $providerConfig['provider'] ?? null;
    $maxAttempts = $retryPolicy->getMaxAttempts();
    $providerSwitchCount = 0;
    $currentProviderName = $providerName;
    $currentOverrides = [];
    $retryLog = [];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $attemptStart = microtime(true);

        try {
            // Build config with potential credential rotation and provider switch
            $runConfig = array_filter([
                'model' => $agentConfig['model'] ?? null,
                'system_prompt' => $agentConfig['system_prompt'] ?? null,
                'provider' => $currentProviderName,
                'api_key' => $providerConfig['api_key'] ?? null,
                'allowed_tools' => $agentConfig['allowed_tools'] ?? null,
                'denied_tools' => $agentConfig['denied_tools'] ?? null,
            ]);

            // Inject rotated credential from pool
            if ($credentialPool !== null && $currentProviderName !== null) {
                $rotatedKey = $credentialPool->getKey($currentProviderName);
                if ($rotatedKey !== null) {
                    $runConfig['api_key'] = $rotatedKey;
                }
            }

            // Apply provider-specific overrides from fallback
            if (!empty($currentOverrides)) {
                $runConfig = array_merge($runConfig, $currentOverrides);
            }

            $agent = new Agent($runConfig);
            /** @var AgentResult $agentResult */
            $agentResult = $agent->run($agentConfig['prompt'] ?? '');

            // Report success to credential pool
            $usedKey = $runConfig['api_key'] ?? null;
            if ($credentialPool !== null && $currentProviderName !== null && $usedKey !== null) {
                $credentialPool->reportSuccess($currentProviderName, $usedKey);
            }

            if ($attempt > 1) {
                fwrite(STDERR, json_encode([
                    'type' => 'agent_retry_success',
                    'phase' => $phaseName,
                    'agent' => $agentName,
                    'attempt' => $attempt,
                    'provider' => $currentProviderName,
                ]) . "\n");
            }

            return [
                'text' => $agentResult->text(),
                'turns' => $agentResult->turns(),
                'cost_usd' => $agentResult->totalCostUsd,
                'retry_log' => $retryLog,
            ];
        } catch (\Throwable $e) {
            $classification = $retryPolicy->classifyError($e);
            $retryLog[] = [
                'attempt' => $attempt,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'classification' => $classification,
                'provider' => $currentProviderName,
                'duration_ms' => (microtime(true) - $attemptStart) * 1000,
            ];

            fwrite(STDERR, json_encode([
                'type' => 'agent_retry',
                'phase' => $phaseName,
                'agent' => $agentName,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'classification' => $classification,
                'error' => $e->getMessage(),
                'provider' => $currentProviderName,
            ]) . "\n");

            // Report error to credential pool
            $usedKey = $runConfig['api_key'] ?? null;
            if ($credentialPool !== null && $currentProviderName !== null && $usedKey !== null) {
                if ($retryPolicy->isRateLimitError($e)) {
                    $credentialPool->reportRateLimit($currentProviderName, $usedKey);
                } else {
                    $credentialPool->reportError($currentProviderName, $usedKey);
                }
            }

            // Should we stop?
            if (!$retryPolicy->shouldRetry($attempt, $e)) {
                return [
                    'text' => '',
                    'turns' => 0,
                    'cost_usd' => 0.0,
                    'error' => $e->getMessage(),
                    'classification' => $classification,
                    'retry_log' => $retryLog,
                ];
            }

            // Provider switch on persistent failure
            if ($retryPolicy->shouldSwitchProvider($attempt, $e)) {
                $fallbackProviders = $agentConfig['fallback_providers'] ?? [];
                $nextProvider = $fallbackProviders[$providerSwitchCount] ?? null;
                if ($nextProvider !== null) {
                    $oldProvider = $currentProviderName;
                    if (is_array($nextProvider)) {
                        $currentProviderName = $nextProvider['name'] ?? $currentProviderName;
                        $currentOverrides = $nextProvider['config'] ?? [];
                    } else {
                        $currentProviderName = $nextProvider;
                        $currentOverrides = [];
                    }
                    $providerSwitchCount++;

                    fwrite(STDERR, json_encode([
                        'type' => 'agent_provider_switch',
                        'phase' => $phaseName,
                        'agent' => $agentName,
                        'from' => $oldProvider,
                        'to' => $currentProviderName,
                    ]) . "\n");
                }
            }

            // Backoff delay
            $delayMs = $retryPolicy->getDelayMs($attempt);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
    }

    return [
        'text' => '',
        'turns' => 0,
        'cost_usd' => 0.0,
        'error' => "Agent '{$agentName}' exhausted all {$maxAttempts} attempts",
        'retry_log' => $retryLog,
    ];
}

foreach ($agents as $agentConfig) {
    $agentName = $agentConfig['name'] ?? 'unnamed';
    $agentStart = microtime(true);

    fwrite(STDERR, json_encode([
        'type' => 'agent_start',
        'phase' => $phaseName,
        'agent' => $agentName,
        'timestamp' => $agentStart,
    ]) . "\n");

    // Use per-agent retry policy if specified, otherwise phase-level
    $agentRetryConfig = $agentConfig['retry_policy'] ?? [];
    $agentRetryPolicy = !empty($agentRetryConfig)
        ? new AgentRetryPolicy(
            maxAttempts: $agentRetryConfig['max_attempts'] ?? $retryPolicy->getMaxAttempts(),
            backoffType: $agentRetryConfig['backoff_type'] ?? $retryPolicy->getBackoffType(),
            baseDelayMs: $agentRetryConfig['base_delay_ms'] ?? $retryPolicy->getBaseDelayMs(),
            maxDelayMs: $agentRetryConfig['max_delay_ms'] ?? $retryPolicy->getMaxDelayMs(),
            jitter: $agentRetryConfig['jitter'] ?? $retryPolicy->hasJitter(),
            rotateCredentialOnRateLimit: $agentRetryConfig['rotate_credential_on_rate_limit'] ?? $retryPolicy->isCredentialRotationEnabled(),
            switchProviderOnFailure: $agentRetryConfig['switch_provider_on_failure'] ?? $retryPolicy->isProviderFallbackEnabled(),
        )
        : $retryPolicy;

    $agentResult = executeAgentWithRetry($agentConfig, $agentRetryPolicy, $credentialPool, $phaseName);

    $results[$agentName] = $agentResult;

    if (isset($agentResult['error'])) {
        $overallStatus = 'failed';
        $error = "Agent '{$agentName}' failed: {$agentResult['error']}";
    }

    fwrite(STDERR, json_encode([
        'type' => isset($agentResult['error']) ? 'agent_error' : 'agent_complete',
        'phase' => $phaseName,
        'agent' => $agentName,
        'turns' => $agentResult['turns'] ?? 0,
        'cost_usd' => $agentResult['cost_usd'] ?? 0.0,
        'duration_ms' => (microtime(true) - $agentStart) * 1000,
        'retries' => count($agentResult['retry_log'] ?? []),
    ]) . "\n");

    // Check timeout
    if ((microtime(true) - $startTime) > $timeout) {
        $overallStatus = 'failed';
        $error = "Phase '{$phaseName}' timed out after {$timeout}s";
        fwrite(STDERR, json_encode([
            'type' => 'phase_timeout',
            'phase' => $phaseName,
            'timeout' => $timeout,
        ]) . "\n");
        break;
    }
}

$durationMs = (microtime(true) - $startTime) * 1000;

// Output final result as JSON on stdout
$output = [
    'phase' => $phaseName,
    'status' => $overallStatus,
    'results' => $results,
    'duration_ms' => round($durationMs, 2),
    'error' => $error,
];

echo json_encode($output);
exit($overallStatus === 'completed' ? 0 : 1);
