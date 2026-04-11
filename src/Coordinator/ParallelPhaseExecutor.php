<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use Fiber;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Agent;
use SuperAgent\AgentResult;
use SuperAgent\Providers\CredentialPool;
use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\AgentStatus;
use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\ParallelAgentCoordinator;

/**
 * Executes all agents within a single phase in parallel.
 *
 * Supports three execution modes:
 * - ProcessBackend: True OS-level parallelism via child processes
 * - InProcessBackend: Fiber-based concurrency within the same process
 * - Fallback: Direct sequential execution when no backend is available
 *
 * Provider-aware features:
 * - Same-provider: Agents share a provider, credentials are rotated via CredentialPool
 * - Cross-provider: Each agent can use a different provider (Anthropic, OpenAI, etc.)
 * - Fallback chain: On failure, try alternate providers automatically
 *
 * Retry features:
 * - Per-agent retry with configurable backoff (exponential, linear, fixed)
 * - Credential rotation on rate-limit (429) errors
 * - Provider switch on persistent failures
 * - Retry logging for observability
 */
class ParallelPhaseExecutor
{
    private LoggerInterface $logger;

    /** @var array<string, array> Retry log for observability */
    private array $retryLog = [];

    public function __construct(
        private ?BackendInterface $backend = null,
        private ?ParallelAgentCoordinator $coordinator = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Execute a phase's agents in parallel and return the phase result.
     *
     * @param CollaborationPhase $phase The phase to execute
     * @param array<string, PhaseResult> $priorResults Results from completed phases (for context injection)
     * @param PipelineListener[]|PipelineListener|null $listeners Event listener(s)
     */
    public function execute(
        CollaborationPhase $phase,
        array $priorResults = [],
        array|PipelineListener|null $listeners = null,
    ): PhaseResult {
        // Normalize to array
        if ($listeners instanceof PipelineListener) {
            $listeners = [$listeners];
        }
        $listeners = $listeners ?? [];
        $result = new PhaseResult($phase->name);
        $result->markRunning();
        $this->retryLog = [];

        $agents = $phase->getAgents();
        if (empty($agents)) {
            $result->markCompleted();
            return $result;
        }

        $this->logger->info("Executing phase '{$phase->name}' with " . count($agents) . " agents");

        // Resolve context injector for cross-phase context sharing
        $contextInjector = $phase->getContextInjector();

        try {
            if ($this->backend instanceof ProcessBackend) {
                $this->executeWithProcessBackend($phase, $result, $listeners, $priorResults, $contextInjector);
            } elseif ($this->backend instanceof InProcessBackend) {
                $this->executeWithFibers($phase, $result, $listeners, $priorResults, $contextInjector);
            } else {
                $this->executeSequential($phase, $result, $priorResults, $listeners, $contextInjector);
            }

            // Check if any agent failed
            $hasFailure = false;
            foreach ($result->getAgentResults() as $agentResult) {
                if ($agentResult->text() === '' && $agentResult->turns() === 0) {
                    $hasFailure = true;
                    break;
                }
            }

            if ($hasFailure) {
                $result->markFailed('One or more agents failed to produce results');
            } else {
                $result->markCompleted();
            }
        } catch (\Throwable $e) {
            $this->logger->error("Phase '{$phase->name}' failed: {$e->getMessage()}");
            $result->markFailed($e->getMessage());
        }

        return $result;
    }

    /**
     * Get the retry log from the last execution.
     *
     * @return array<string, array> Agent name => retry entries
     */
    public function getRetryLog(): array
    {
        return $this->retryLog;
    }

    /**
     * Execute agents via OS processes for true parallelism.
     *
     * @param PipelineListener[] $listeners
     * @param array<string, PhaseResult> $priorResults
     */
    private function executeWithProcessBackend(
        CollaborationPhase $phase,
        PhaseResult $result,
        array $listeners,
        array $priorResults = [],
        ?PhaseContextInjector $contextInjector = null,
    ): void {
        /** @var ProcessBackend $backend */
        $backend = $this->backend;
        $spawnMap = []; // agentId => agentName

        // Spawn all agents with provider-aware config and prior phase context
        foreach ($phase->getAgents() as $config) {
            $config = $this->injectProviderConfig($phase, $config);
            if ($contextInjector !== null) {
                $config = $contextInjector->injectIntoConfig($config, $priorResults);
            }
            $spawnResult = $backend->spawn($config);
            if ($spawnResult->success) {
                $spawnMap[$spawnResult->agentId] = $config->name;
                foreach ($listeners as $l) {
                    $l->onAgentSpawned($phase->name, $spawnResult->agentId, $config->name);
                }
                $this->logger->debug("Spawned process agent '{$config->name}' (pid: {$spawnResult->pid})");
            } else {
                $this->logger->warning("Failed to spawn agent '{$config->name}': {$spawnResult->error}");
                $result->addAgentResult($config->name, new AgentResult(null));
            }
        }

        // Wait for all processes to complete
        $timeout = $phase->getTimeoutSeconds();
        $deadline = time() + $timeout;
        $backend->waitAll($timeout);

        // Collect results, identify failures for retry
        $failedAgents = [];
        $configMap = [];
        foreach ($phase->getAgents() as $cfg) {
            $configMap[$cfg->name] = $cfg;
        }

        foreach ($spawnMap as $agentId => $agentName) {
            $rawResult = $backend->getResult($agentId);

            if ($rawResult !== null && ($rawResult['success'] ?? false)) {
                $agentResult = new AgentResult(
                    message: null,
                    allResponses: [],
                    messages: [],
                    totalCostUsd: $rawResult['cost_usd'] ?? 0.0,
                );
                $result->addAgentResult($agentName, $agentResult);

                // Report success to credential pool
                $providerConfig = $phase->getProviderConfigFor($agentName);
                $poolKey = $rawResult['_pool_key'] ?? null;
                if ($providerConfig !== null && $poolKey !== null) {
                    $providerConfig->reportSuccess($poolKey);
                }
            } elseif ($rawResult !== null) {
                // Has result but not successful -- candidate for retry
                $failedAgents[$agentName] = $rawResult['error'] ?? 'Agent produced unsuccessful result';
            } else {
                // Null result -- process may have crashed
                $failedAgents[$agentName] = 'Process returned no result';
            }

            foreach ($listeners as $l) {
                $l->onAgentComplete($phase->name, $agentId, $agentName);
            }
            $backend->cleanup($agentId);
        }

        // Retry failed agents with backoff, credential rotation, provider switching
        if (!empty($failedAgents)) {
            $this->retryFailedAgents($phase, $result, $failedAgents, $configMap, $deadline, $listeners, $priorResults, $contextInjector);
        }
    }

    /**
     * Execute agents concurrently using PHP Fibers.
     *
     * @param PipelineListener[] $listeners
     * @param array<string, PhaseResult> $priorResults
     */
    private function executeWithFibers(
        CollaborationPhase $phase,
        PhaseResult $result,
        array $listeners,
        array $priorResults = [],
        ?PhaseContextInjector $contextInjector = null,
    ): void {
        /** @var InProcessBackend $backend */
        $backend = $this->backend;
        $spawnMap = [];

        // Spawn all agents as fibers with provider-aware config and prior phase context
        foreach ($phase->getAgents() as $config) {
            $config = $this->injectProviderConfig($phase, $config);
            if ($contextInjector !== null) {
                $config = $contextInjector->injectIntoConfig($config, $priorResults);
            }
            $spawnResult = $backend->spawn($config);
            if ($spawnResult->success) {
                $spawnMap[$spawnResult->agentId] = $config->name;
                foreach ($listeners as $l) {
                    $l->onAgentSpawned($phase->name, $spawnResult->agentId, $config->name);
                }
            } else {
                $result->addAgentResult($config->name, new AgentResult(null));
            }
        }

        // Process fibers until all complete
        $timeout = $phase->getTimeoutSeconds();
        $deadline = microtime(true) + $timeout;

        while (!empty($spawnMap) && microtime(true) < $deadline) {
            $backend->processMessages();

            // Check which agents are done
            $completed = [];
            foreach ($spawnMap as $agentId => $agentName) {
                if (!$backend->isRunning($agentId)) {
                    $completed[] = $agentId;
                }
            }

            foreach ($completed as $agentId) {
                $agentName = $spawnMap[$agentId];
                unset($spawnMap[$agentId]);

                // Get result from coordinator
                $agentResult = $this->coordinator?->getAgentResult($agentId);
                $result->addAgentResult($agentName, $agentResult ?? new AgentResult(null));
                foreach ($listeners as $l) {
                    $l->onAgentComplete($phase->name, $agentId, $agentName);
                }
                $backend->cleanup($agentId);
            }

            if (!empty($spawnMap)) {
                usleep(10_000); // 10ms polling interval
            }
        }

        // Handle timeout — collect timed-out agents as failures for retry
        $failedAgents = [];
        foreach ($spawnMap as $agentId => $agentName) {
            $this->logger->warning("Agent '{$agentName}' timed out in phase '{$phase->name}'");
            $backend->kill($agentId);
            $failedAgents[$agentName] = "Timed out after {$timeout}s";
        }

        // Also check completed agents that returned null results
        foreach ($result->getAgentResults() as $agentName => $agentResult) {
            if ($agentResult->text() === '' && $agentResult->turns() === 0 && !isset($failedAgents[$agentName])) {
                $failedAgents[$agentName] = 'Agent returned empty result';
            }
        }

        // Retry failed agents
        if (!empty($failedAgents)) {
            $configMap = [];
            foreach ($phase->getAgents() as $cfg) {
                $configMap[$cfg->name] = $cfg;
            }
            $intDeadline = (int) ceil($deadline);
            $this->retryFailedAgents($phase, $result, $failedAgents, $configMap, $intDeadline, $listeners, $priorResults, $contextInjector);
        }
    }

    /**
     * Execute agents sequentially with per-agent retry and provider fallback.
     *
     * @param PipelineListener[] $listeners
     * @param array<string, PhaseResult> $priorResults
     */
    private function executeSequential(
        CollaborationPhase $phase,
        PhaseResult $result,
        array $priorResults,
        array $listeners,
        ?PhaseContextInjector $contextInjector = null,
    ): void {
        foreach ($phase->getAgents() as $config) {
            if ($contextInjector !== null) {
                $config = $contextInjector->injectIntoConfig($config, $priorResults);
            }
            $agentId = 'seq_' . uniqid();
            foreach ($listeners as $l) {
                $l->onAgentSpawned($phase->name, $agentId, $config->name);
            }

            $agentResult = $this->executeAgentWithRetry($phase, $config);
            $result->addAgentResult($config->name, $agentResult);

            foreach ($listeners as $l) {
                $l->onAgentComplete($phase->name, $agentId, $config->name);
            }
        }
    }

    /**
     * Retry failed agents from parallel execution (ProcessBackend or Fiber).
     *
     * Falls back to sequential execution with full retry logic for each failed agent.
     *
     * @param array<string, string> $failedAgents agentName => error message
     * @param array<string, AgentSpawnConfig> $configMap agentName => original config
     * @param PipelineListener[] $listeners
     * @param array<string, PhaseResult> $priorResults
     */
    private function retryFailedAgents(
        CollaborationPhase $phase,
        PhaseResult $result,
        array $failedAgents,
        array $configMap,
        int $deadline,
        array $listeners,
        array $priorResults = [],
        ?PhaseContextInjector $contextInjector = null,
    ): void {
        foreach ($failedAgents as $agentName => $errorMsg) {
            if (time() >= $deadline) {
                $this->logger->warning("Retry deadline passed, skipping retry for '{$agentName}'");
                if ($result->getAgentResult($agentName) === null) {
                    $result->addAgentResult($agentName, new AgentResult(null));
                }
                continue;
            }

            $config = $configMap[$agentName] ?? null;
            if ($config === null) {
                $this->logger->warning("No config found for failed agent '{$agentName}', skipping retry");
                if ($result->getAgentResult($agentName) === null) {
                    $result->addAgentResult($agentName, new AgentResult(null));
                }
                continue;
            }

            $retryPolicy = $phase->getRetryPolicyFor($agentName);
            if ($retryPolicy->getMaxAttempts() <= 1) {
                $this->logger->debug("No retries configured for '{$agentName}'");
                if ($result->getAgentResult($agentName) === null) {
                    $result->addAgentResult($agentName, new AgentResult(null));
                }
                continue;
            }

            $this->logger->info("Retrying failed agent '{$agentName}': {$errorMsg}");

            // Inject context if available
            if ($contextInjector !== null) {
                $config = $contextInjector->injectIntoConfig($config, $priorResults);
            }

            // Use the sequential retry logic (attempt 2+ since parallel was attempt 1)
            $agentResult = $this->executeAgentWithRetry($phase, $config);

            // Override the failed result
            $result->addAgentResult($agentName, $agentResult);
        }
    }

    /**
     * Execute a single agent with retry logic, credential rotation, and provider fallback.
     */
    private function executeAgentWithRetry(
        CollaborationPhase $phase,
        AgentSpawnConfig $config,
    ): AgentResult {
        $retryPolicy = $phase->getRetryPolicyFor($config->name);
        $providerConfig = $phase->getProviderConfigFor($config->name);
        $maxAttempts = $retryPolicy->getMaxAttempts();
        $providerSwitchCount = 0;
        $currentProviderName = $providerConfig?->getProviderName() ?? $config->providerConfig['provider'] ?? null;
        $currentProviderOverrides = [];
        $agentConfig = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $agentConfig = $this->buildAgentConfig($config, $providerConfig, $currentProviderName, $currentProviderOverrides);
                $agent = new Agent($agentConfig);
                $agentResult = $agent->run($config->prompt);

                // Report success to credential pool
                $usedKey = $agentConfig['api_key'] ?? null;
                if ($providerConfig !== null && $usedKey !== null) {
                    $providerConfig->reportSuccess($usedKey);
                }

                if ($attempt > 1) {
                    $this->logger->info("Agent '{$config->name}' succeeded on attempt {$attempt}");
                }

                return $agentResult;
            } catch (\Throwable $e) {
                $classification = $retryPolicy->classifyError($e);
                $this->logRetry($config->name, $attempt, $e, $classification);

                // Report error to credential pool
                $usedKey = $agentConfig['api_key'] ?? null;
                if ($providerConfig !== null && $usedKey !== null) {
                    if ($retryPolicy->isRateLimitError($e)) {
                        $providerConfig->reportRateLimit($usedKey);
                    } else {
                        $providerConfig->reportError($usedKey);
                    }
                }

                // Should we retry?
                if (!$retryPolicy->shouldRetry($attempt, $e)) {
                    $this->logger->error(
                        "Agent '{$config->name}' failed permanently ({$classification}): {$e->getMessage()}"
                    );
                    return new AgentResult(null);
                }

                // Credential rotation on rate limit
                if ($retryPolicy->shouldRotateCredential($e) && $providerConfig !== null) {
                    $this->logger->info("Rotating credential for agent '{$config->name}' after rate limit");
                    // CredentialPool will return a different key on next getKey() call
                    // because the current key was just reported as rate-limited
                }

                // Provider switch on persistent failure
                if ($retryPolicy->shouldSwitchProvider($attempt, $e)) {
                    $nextProvider = $retryPolicy->getNextFallbackProvider($providerSwitchCount);
                    if ($nextProvider !== null) {
                        $this->logger->info(
                            "Switching agent '{$config->name}' from '{$currentProviderName}' to '{$nextProvider}'"
                        );
                        $currentProviderName = $nextProvider;
                        $currentProviderOverrides = $retryPolicy->getFallbackProviderConfig($nextProvider);
                        $providerSwitchCount++;
                    }
                }

                // Wait before retry
                $delayMs = $retryPolicy->getDelayMs($attempt);
                if ($delayMs > 0) {
                    $this->logger->debug(
                        "Agent '{$config->name}' retry in {$delayMs}ms (attempt {$attempt}/{$maxAttempts})"
                    );
                    usleep($delayMs * 1000);
                }
            }
        }

        return new AgentResult(null);
    }

    /**
     * Build agent configuration array, merging spawn config with provider config.
     */
    private function buildAgentConfig(
        AgentSpawnConfig $spawnConfig,
        ?AgentProviderConfig $providerConfig,
        ?string $providerName,
        array $providerOverrides = [],
    ): array {
        $config = array_filter([
            'model' => $spawnConfig->model,
            'system_prompt' => $spawnConfig->systemPrompt,
            'allowed_tools' => $spawnConfig->allowedTools,
            'denied_tools' => $spawnConfig->deniedTools,
        ]);

        // Layer 1: spawn config's own provider config
        if (!empty($spawnConfig->providerConfig)) {
            $config = array_merge($config, array_filter($spawnConfig->providerConfig));
        }

        // Layer 2: phase/pipeline provider config
        if ($providerConfig !== null) {
            $spawnProviderConfig = $providerConfig->toSpawnConfig();
            // Only merge non-null values, don't override explicit spawn config
            foreach ($spawnProviderConfig as $key => $value) {
                if ($value !== null && !isset($config[$key])) {
                    $config[$key] = $value;
                }
            }
        }

        // Layer 3: provider name override (from retry fallback)
        if ($providerName !== null) {
            $config['provider'] = $providerName;
        }

        // Layer 4: provider-specific overrides (from retry fallback config)
        if (!empty($providerOverrides)) {
            $config = array_merge($config, $providerOverrides);
        }

        return $config;
    }

    /**
     * Inject provider configuration from phase into agent spawn config.
     * Used for backend-based execution where agents are spawned as separate processes/fibers.
     */
    private function injectProviderConfig(
        CollaborationPhase $phase,
        AgentSpawnConfig $config,
    ): AgentSpawnConfig {
        $providerConfig = $phase->getProviderConfigFor($config->name);
        if ($providerConfig === null) {
            return $config;
        }

        $spawnProviderConfig = $providerConfig->toSpawnConfig();
        $mergedProviderConfig = array_merge($config->providerConfig, array_filter($spawnProviderConfig));

        return new AgentSpawnConfig(
            name: $config->name,
            prompt: $config->prompt,
            teamName: $config->teamName,
            model: $config->model ?? ($spawnProviderConfig['model'] ?? null),
            systemPrompt: $config->systemPrompt,
            permissionMode: $config->permissionMode,
            backend: $config->backend,
            isolation: $config->isolation,
            runInBackground: $config->runInBackground,
            allowedTools: $config->allowedTools,
            deniedTools: $config->deniedTools,
            workingDirectory: $config->workingDirectory,
            environment: $config->environment,
            color: $config->color,
            planModeRequired: $config->planModeRequired,
            readOnly: $config->readOnly,
            forkContext: $config->forkContext,
            providerConfig: $mergedProviderConfig,
        );
    }

    /**
     * Record a retry attempt for observability.
     */
    private function logRetry(string $agentName, int $attempt, \Throwable $error, string $classification): void
    {
        $this->retryLog[$agentName][] = [
            'attempt' => $attempt,
            'error' => $error->getMessage(),
            'code' => $error->getCode(),
            'classification' => $classification,
            'timestamp' => microtime(true),
        ];

        $this->logger->warning(
            "Agent '{$agentName}' attempt {$attempt} failed ({$classification}): {$error->getMessage()}"
        );
    }

    public function getBackend(): ?BackendInterface
    {
        return $this->backend;
    }

    public function setBackend(BackendInterface $backend): void
    {
        $this->backend = $backend;
    }
}
