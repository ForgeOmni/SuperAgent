<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Providers\CredentialPool;
use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\ParallelAgentCoordinator;

/**
 * Orchestrates multi-agent collaboration through a phased pipeline.
 *
 * Phases execute in dependency order (topological sort). Within each phase,
 * agents run in parallel via the configured backend. Supports conditional
 * execution, failure strategies (fail-fast, continue, retry, fallback),
 * and event listeners for observability.
 *
 * Provider support:
 *   - Same provider: all agents share credentials, rotated via CredentialPool
 *   - Cross provider: mix Anthropic, OpenAI, Ollama, etc. in one pipeline
 *   - Fallback chain: automatic provider switching on failure
 *
 * Retry support:
 *   - Phase-level retry (FailureStrategy::RETRY)
 *   - Agent-level retry with backoff (AgentRetryPolicy)
 *   - Credential rotation on rate limits
 *   - Provider fallback on persistent failures
 *
 * Usage:
 *   $pool = CredentialPool::fromConfig([
 *       'anthropic' => ['strategy' => 'round_robin', 'keys' => ['key1', 'key2']],
 *   ]);
 *
 *   $pipeline = CollaborationPipeline::create()
 *       ->withDefaultProvider(AgentProviderConfig::sameProvider('anthropic', $pool))
 *       ->withDefaultRetryPolicy(AgentRetryPolicy::default())
 *       ->phase('research', function (CollaborationPhase $phase) {
 *           $phase->addAgent(new AgentSpawnConfig(name: 'researcher-1', prompt: '...'));
 *           $phase->addAgent(new AgentSpawnConfig(name: 'researcher-2', prompt: '...'));
 *           // Both agents use 'anthropic' with different rotated keys
 *       })
 *       ->phase('review', function (CollaborationPhase $phase) {
 *           $phase->dependsOn('research');
 *           // Override: this agent uses OpenAI
 *           $phase->withAgentProvider('reviewer', AgentProviderConfig::crossProvider('openai', [
 *               'api_key' => 'sk-...',
 *               'model' => 'gpt-4o',
 *           ]));
 *           $phase->addAgent(new AgentSpawnConfig(name: 'reviewer', prompt: '...'));
 *       });
 *
 *   $result = $pipeline->run();
 */
class CollaborationPipeline
{
    /** @var array<string, CollaborationPhase> */
    private array $phases = [];

    /** @var PipelineListener[] */
    private array $listeners = [];

    private ParallelPhaseExecutor $executor;
    private LoggerInterface $logger;

    /** Pipeline-level default provider config (inherited by phases that don't set their own) */
    private ?AgentProviderConfig $defaultProviderConfig = null;

    /** Pipeline-level default retry policy (inherited by phases that don't set their own) */
    private ?AgentRetryPolicy $defaultRetryPolicy = null;

    /** Pipeline-level auto-routing */
    private ?TaskRouter $taskRouter = null;
    private bool $autoRouting = false;

    public function __construct(
        ?BackendInterface $backend = null,
        ?ParallelAgentCoordinator $coordinator = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->executor = new ParallelPhaseExecutor($backend, $coordinator, $this->logger);
    }

    /**
     * Factory method.
     */
    public static function create(
        ?BackendInterface $backend = null,
        ?ParallelAgentCoordinator $coordinator = null,
        ?LoggerInterface $logger = null,
    ): static {
        return new static($backend, $coordinator, $logger);
    }

    /**
     * Define a phase using a builder callback.
     *
     * @param string $name Phase name
     * @param callable(CollaborationPhase): void $builder
     */
    public function phase(string $name, callable $builder): static
    {
        $phase = new CollaborationPhase($name);

        // Inherit pipeline-level defaults if the phase hasn't set its own
        if ($this->defaultProviderConfig !== null) {
            $phase->withProvider($this->defaultProviderConfig);
        }
        if ($this->defaultRetryPolicy !== null) {
            $phase->withRetryPolicy($this->defaultRetryPolicy);
        }
        if ($this->autoRouting && !$phase->isAutoRoutingEnabled()) {
            $phase->withAutoRouting($this->taskRouter);
        }

        $builder($phase);
        $this->phases[$name] = $phase;
        return $this;
    }

    /**
     * Add a pre-built phase.
     */
    public function addPhase(CollaborationPhase $phase): static
    {
        // Inject defaults if phase doesn't have its own
        if ($phase->getProviderConfig() === null && $this->defaultProviderConfig !== null) {
            $phase->withProvider($this->defaultProviderConfig);
        }
        if ($phase->getRetryPolicy() === null && $this->defaultRetryPolicy !== null) {
            $phase->withRetryPolicy($this->defaultRetryPolicy);
        }
        if ($this->autoRouting && !$phase->isAutoRoutingEnabled()) {
            $phase->withAutoRouting($this->taskRouter);
        }

        $this->phases[$phase->name] = $phase;
        return $this;
    }

    /**
     * Register an event listener.
     */
    public function addListener(PipelineListener $listener): static
    {
        $this->listeners[] = $listener;
        return $this;
    }

    /**
     * Set the execution backend.
     */
    public function withBackend(BackendInterface $backend): static
    {
        $this->executor->setBackend($backend);
        return $this;
    }

    // ── Pipeline-level provider/retry defaults ──────────────────

    /**
     * Set default provider config for all phases.
     * Phases can override with their own withProvider().
     */
    public function withDefaultProvider(AgentProviderConfig $config): static
    {
        $this->defaultProviderConfig = $config;
        return $this;
    }

    /**
     * Set default provider by name for all phases.
     * Shorthand for withDefaultProvider(AgentProviderConfig::sameProvider(...)).
     */
    public function withDefaultProviderName(string $providerName, array $config = []): static
    {
        $this->defaultProviderConfig = AgentProviderConfig::sameProvider($providerName, config: $config);
        return $this;
    }

    /**
     * Set a credential pool for the entire pipeline.
     * All phases will use this pool for credential rotation unless overridden.
     */
    public function withCredentialPool(CredentialPool $pool): static
    {
        if ($this->defaultProviderConfig === null) {
            $this->defaultProviderConfig = new AgentProviderConfig(credentialPool: $pool);
        } else {
            $this->defaultProviderConfig->withCredentialPool($pool);
        }
        return $this;
    }

    /**
     * Set default retry policy for all phases.
     * Phases can override with their own withRetryPolicy().
     */
    public function withDefaultRetryPolicy(AgentRetryPolicy $policy): static
    {
        $this->defaultRetryPolicy = $policy;
        return $this;
    }

    public function getDefaultProviderConfig(): ?AgentProviderConfig
    {
        return $this->defaultProviderConfig;
    }

    public function getDefaultRetryPolicy(): ?AgentRetryPolicy
    {
        return $this->defaultRetryPolicy;
    }

    // ── Auto-routing ───────────────────────────────────────────

    /**
     * Enable automatic task-to-model routing for all phases.
     *
     * Agents are routed to optimal model tiers based on prompt analysis:
     *   - Research/chat → Tier 3 (Haiku, cheap & fast)
     *   - Code/debug/analysis → Tier 2 (Sonnet, balanced)
     *   - Synthesis/coordination → Tier 1 (Opus, powerful)
     *
     * Phases and agents with explicit provider configs are not affected.
     */
    public function withAutoRouting(?TaskRouter $router = null): static
    {
        $this->autoRouting = true;
        $this->taskRouter = $router ?? TaskRouter::withDefaults();
        return $this;
    }

    public function isAutoRoutingEnabled(): bool
    {
        return $this->autoRouting;
    }

    public function getTaskRouter(): ?TaskRouter
    {
        return $this->taskRouter;
    }

    /**
     * Execute the pipeline.
     */
    public function run(): CollaborationResult
    {
        $result = new CollaborationResult();
        $result->markRunning();

        $ordered = $this->topologicalSort();
        $this->notifyPipelineStart($ordered);

        $this->logger->info('Starting collaboration pipeline with ' . count($ordered) . ' phases');

        /** @var array<string, PhaseResult> $phaseResults */
        $phaseResults = [];

        foreach ($ordered as $phaseName) {
            $phase = $this->phases[$phaseName];

            // Check condition
            if (!$phase->shouldRun($phaseResults)) {
                $this->logger->info("Skipping phase '{$phaseName}': condition not met");
                $result->addSkippedPhase($phaseName);
                $this->notifyPhaseSkipped($phaseName, 'Condition not met');
                continue;
            }

            // Execute with failure strategy
            $phaseResult = $this->executePhaseWithStrategy($phase, $phaseResults);
            $phaseResults[$phaseName] = $phaseResult;
            $result->addPhaseResult($phaseResult);

            // Handle failure
            if (!$phaseResult->isSuccessful() && $phase->getFailureStrategy() === FailureStrategy::FAIL_FAST) {
                $this->logger->error("Pipeline aborted: phase '{$phaseName}' failed (fail-fast)");
                $result->markFailed();
                $this->notifyPipelineComplete($result);
                return $result;
            }
        }

        $hasFailures = !empty($result->getFailedPhases());
        if ($hasFailures) {
            $result->markFailed();
        } else {
            $result->markCompleted();
        }

        $this->logger->info('Pipeline finished: ' . $result->summary());
        $this->notifyPipelineComplete($result);

        return $result;
    }

    /**
     * Execute a phase respecting its failure strategy.
     */
    private function executePhaseWithStrategy(
        CollaborationPhase $phase,
        array $priorResults,
    ): PhaseResult {
        $strategy = $phase->getFailureStrategy();
        $phaseName = $phase->name;

        $this->notifyPhaseStart($phaseName, $phase->getAgentCount());

        $phaseResult = $this->executor->execute($phase, $priorResults, $this->listeners);

        if ($phaseResult->isSuccessful()) {
            $this->notifyPhaseComplete($phaseName, $phaseResult);
            return $phaseResult;
        }

        // Phase failed — apply strategy
        $error = $phaseResult->getError() ?? 'Unknown error';
        $this->notifyPhaseFailed($phaseName, $error, $strategy);

        switch ($strategy) {
            case FailureStrategy::RETRY:
                return $this->retryPhase($phase, $priorResults, $phaseResult);

            case FailureStrategy::FALLBACK:
                return $this->executeFallback($phase, $priorResults, $phaseResult);

            case FailureStrategy::CONTINUE:
                $this->logger->warning("Phase '{$phaseName}' failed but continuing: {$error}");
                return $phaseResult;

            case FailureStrategy::FAIL_FAST:
            default:
                return $phaseResult;
        }
    }

    /**
     * Retry a failed phase.
     */
    private function retryPhase(
        CollaborationPhase $phase,
        array $priorResults,
        PhaseResult $lastResult,
    ): PhaseResult {
        $maxRetries = $phase->getMaxRetries();

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->logger->info("Retrying phase '{$phase->name}' (attempt {$attempt}/{$maxRetries})");
            $this->notifyPhaseRetry($phase->name, $attempt, $maxRetries);

            $phaseResult = $this->executor->execute($phase, $priorResults, $this->listeners);

            if ($phaseResult->isSuccessful()) {
                $this->notifyPhaseComplete($phase->name, $phaseResult);
                return $phaseResult;
            }

            $lastResult = $phaseResult;
        }

        $this->logger->error("Phase '{$phase->name}' failed after {$maxRetries} retries");
        return $lastResult;
    }

    /**
     * Execute a fallback phase when the primary fails.
     */
    private function executeFallback(
        CollaborationPhase $phase,
        array $priorResults,
        PhaseResult $failedResult,
    ): PhaseResult {
        $fallbackName = $phase->getFallbackPhase();
        if ($fallbackName === null || !isset($this->phases[$fallbackName])) {
            $this->logger->warning("No valid fallback phase for '{$phase->name}'");
            return $failedResult;
        }

        $this->logger->info("Executing fallback phase '{$fallbackName}' for failed phase '{$phase->name}'");
        $fallbackPhase = $this->phases[$fallbackName];

        return $this->executor->execute($fallbackPhase, $priorResults, $this->listeners);
    }

    /**
     * Topological sort of phases based on dependencies.
     *
     * @return string[] Ordered phase names
     * @throws \RuntimeException If circular dependency detected
     */
    private function topologicalSort(): array
    {
        $sorted = [];
        $visited = [];
        $visiting = [];

        foreach (array_keys($this->phases) as $name) {
            if (!isset($visited[$name])) {
                $this->topologicalVisit($name, $sorted, $visited, $visiting);
            }
        }

        return $sorted;
    }

    private function topologicalVisit(
        string $name,
        array &$sorted,
        array &$visited,
        array &$visiting,
    ): void {
        if (isset($visiting[$name])) {
            throw new \RuntimeException("Circular dependency detected involving phase '{$name}'");
        }

        if (isset($visited[$name])) {
            return;
        }

        $visiting[$name] = true;

        if (isset($this->phases[$name])) {
            foreach ($this->phases[$name]->getDependencies() as $dep) {
                if (!isset($this->phases[$dep])) {
                    throw new \RuntimeException("Phase '{$name}' depends on undefined phase '{$dep}'");
                }
                $this->topologicalVisit($dep, $sorted, $visited, $visiting);
            }
        }

        unset($visiting[$name]);
        $visited[$name] = true;
        $sorted[] = $name;
    }

    /**
     * Get phases in execution order (for inspection).
     *
     * @return string[]
     */
    public function getExecutionOrder(): array
    {
        return $this->topologicalSort();
    }

    /**
     * Get all registered phases.
     *
     * @return array<string, CollaborationPhase>
     */
    public function getPhases(): array
    {
        return $this->phases;
    }

    public function getPhase(string $name): ?CollaborationPhase
    {
        return $this->phases[$name] ?? null;
    }

    // --- Listener notification helpers ---

    private function notifyPipelineStart(array $phaseNames): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPipelineStart($phaseNames);
        }
    }

    private function notifyPipelineComplete(CollaborationResult $result): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPipelineComplete($result);
        }
    }

    private function notifyPhaseStart(string $name, int $agentCount): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPhaseStart($name, $agentCount);
        }
    }

    private function notifyPhaseComplete(string $name, PhaseResult $result): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPhaseComplete($name, $result);
        }
    }

    private function notifyPhaseFailed(string $name, string $error, FailureStrategy $strategy): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPhaseFailed($name, $error, $strategy);
        }
    }

    private function notifyPhaseSkipped(string $name, string $reason): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPhaseSkipped($name, $reason);
        }
    }

    private function notifyPhaseRetry(string $name, int $attempt, int $maxRetries): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPhaseRetry($name, $attempt, $maxRetries);
        }
    }
}
