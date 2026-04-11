<?php

declare(strict_types=1);

namespace SuperAgent\Coordinator;

use SuperAgent\Providers\CredentialPool;
use SuperAgent\Swarm\AgentSpawnConfig;

/**
 * Defines a single phase in a collaboration pipeline.
 *
 * Each phase contains one or more agents that execute in parallel.
 * Phases can depend on other phases, forming a DAG.
 *
 * Provider support:
 *   - Phase-level provider: all agents in the phase share the same provider
 *   - Per-agent provider: each agent can override with its own AgentProviderConfig
 *   - Credential pool: automatic key rotation across parallel agents
 *
 * Retry support:
 *   - Phase-level retry: retry the entire phase on failure (FailureStrategy::RETRY)
 *   - Agent-level retry: per-agent retry policy (AgentRetryPolicy)
 */
class CollaborationPhase
{
    /** @var AgentSpawnConfig[] */
    private array $agents = [];

    /** @var string[] Phase names this phase depends on */
    private array $dependencies = [];

    /** @var callable|null Condition to evaluate before running (receives prior PhaseResults) */
    private $condition = null;

    /** @var string|null Fallback phase name for FailureStrategy::FALLBACK */
    private ?string $fallbackPhase = null;

    /** @var AgentProviderConfig|null Phase-level default provider config */
    private ?AgentProviderConfig $providerConfig = null;

    /** @var array<string, AgentProviderConfig> Per-agent provider overrides (keyed by agent name) */
    private array $agentProviderConfigs = [];

    /** @var AgentRetryPolicy|null Phase-level default retry policy */
    private ?AgentRetryPolicy $retryPolicy = null;

    /** @var array<string, AgentRetryPolicy> Per-agent retry policy overrides (keyed by agent name) */
    private array $agentRetryPolicies = [];

    /** @var PhaseContextInjector|null Context injector for prior phase results */
    private ?PhaseContextInjector $contextInjector = null;

    /** @var bool Whether context injection is enabled (default: true) */
    private bool $contextInjectionEnabled = true;

    /** @var TaskRouter|null Auto-routing engine for task-to-model mapping */
    private ?TaskRouter $taskRouter = null;

    /** @var bool Whether auto-routing is enabled */
    private bool $autoRouting = false;

    public function __construct(
        public readonly string $name,
        private FailureStrategy $failureStrategy = FailureStrategy::FAIL_FAST,
        private int $maxRetries = 1,
        private int $timeoutSeconds = 300,
    ) {}

    /**
     * Add an agent to this phase.
     */
    public function addAgent(AgentSpawnConfig $config): static
    {
        $this->agents[] = $config;
        return $this;
    }

    /**
     * Add multiple agents at once.
     *
     * @param AgentSpawnConfig[] $configs
     */
    public function addAgents(array $configs): static
    {
        foreach ($configs as $config) {
            $this->agents[] = $config;
        }
        return $this;
    }

    /**
     * Declare a dependency on another phase.
     */
    public function dependsOn(string ...$phaseNames): static
    {
        foreach ($phaseNames as $name) {
            if (!in_array($name, $this->dependencies, true)) {
                $this->dependencies[] = $name;
            }
        }
        return $this;
    }

    /**
     * Set a condition that must be true for this phase to execute.
     *
     * @param callable(array<string, PhaseResult>): bool $condition
     */
    public function when(callable $condition): static
    {
        $this->condition = $condition;
        return $this;
    }

    /**
     * Set the failure strategy.
     */
    public function onFailure(FailureStrategy $strategy): static
    {
        $this->failureStrategy = $strategy;
        return $this;
    }

    /**
     * Set fallback phase (used with FailureStrategy::FALLBACK).
     */
    public function withFallback(string $phaseName): static
    {
        $this->fallbackPhase = $phaseName;
        $this->failureStrategy = FailureStrategy::FALLBACK;
        return $this;
    }

    /**
     * Set max retries (used with FailureStrategy::RETRY).
     */
    public function withRetries(int $max): static
    {
        $this->maxRetries = $max;
        $this->failureStrategy = FailureStrategy::RETRY;
        return $this;
    }

    /**
     * Set timeout in seconds.
     */
    public function withTimeout(int $seconds): static
    {
        $this->timeoutSeconds = $seconds;
        return $this;
    }

    /**
     * Check if this phase should run given prior results.
     *
     * @param array<string, PhaseResult> $priorResults
     */
    public function shouldRun(array $priorResults): bool
    {
        if ($this->condition === null) {
            return true;
        }
        return ($this->condition)($priorResults);
    }

    /** @return AgentSpawnConfig[] */
    public function getAgents(): array
    {
        return $this->agents;
    }

    /** @return string[] */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getFailureStrategy(): FailureStrategy
    {
        return $this->failureStrategy;
    }

    public function getFallbackPhase(): ?string
    {
        return $this->fallbackPhase;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getAgentCount(): int
    {
        return count($this->agents);
    }

    // ── Provider configuration ──────────────────────────────────

    /**
     * Set default provider for all agents in this phase.
     * Agents without their own AgentProviderConfig will use this.
     */
    public function withProvider(AgentProviderConfig $config): static
    {
        $this->providerConfig = $config;
        return $this;
    }

    /**
     * Set provider for all agents using a simple provider name.
     * Shorthand for withProvider(AgentProviderConfig::sameProvider(...)).
     */
    public function withProviderName(string $providerName, array $config = []): static
    {
        $this->providerConfig = AgentProviderConfig::sameProvider($providerName, config: $config);
        return $this;
    }

    /**
     * Set a credential pool for key rotation across parallel agents.
     */
    public function withCredentialPool(CredentialPool $pool): static
    {
        if ($this->providerConfig === null) {
            $this->providerConfig = new AgentProviderConfig(credentialPool: $pool);
        } else {
            $this->providerConfig->withCredentialPool($pool);
        }
        return $this;
    }

    /**
     * Override provider config for a specific agent (by name).
     */
    public function withAgentProvider(string $agentName, AgentProviderConfig $config): static
    {
        $this->agentProviderConfigs[$agentName] = $config;
        return $this;
    }

    /**
     * Get the effective provider config for an agent.
     *
     * Priority: explicit per-agent override → auto-routing → phase-level default.
     */
    public function getProviderConfigFor(string $agentName): ?AgentProviderConfig
    {
        // 1. Explicit per-agent override always wins
        if (isset($this->agentProviderConfigs[$agentName])) {
            return $this->agentProviderConfigs[$agentName];
        }

        // 2. Auto-routing based on agent's prompt
        if ($this->autoRouting && $this->taskRouter !== null) {
            $agent = $this->findAgentByName($agentName);
            if ($agent !== null) {
                return $this->taskRouter->routeToProviderConfig($agent->prompt);
            }
        }

        // 3. Phase-level default
        return $this->providerConfig;
    }

    public function getProviderConfig(): ?AgentProviderConfig
    {
        return $this->providerConfig;
    }

    // ── Auto-routing ───────────────────────────────────────────

    /**
     * Enable automatic task-to-model routing for agents in this phase.
     *
     * When enabled, agents without explicit provider overrides will be
     * routed to the optimal model tier based on their prompt content.
     */
    public function withAutoRouting(?TaskRouter $router = null): static
    {
        $this->autoRouting = true;
        $this->taskRouter = $router ?? TaskRouter::withDefaults();
        return $this;
    }

    /**
     * Disable auto-routing.
     */
    public function withoutAutoRouting(): static
    {
        $this->autoRouting = false;
        $this->taskRouter = null;
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
     * Find an agent config by name.
     */
    private function findAgentByName(string $name): ?\SuperAgent\Swarm\AgentSpawnConfig
    {
        foreach ($this->agents as $agent) {
            if ($agent->name === $name) {
                return $agent;
            }
        }
        return null;
    }

    // ── Context injection ───────────────────────────────────────

    /**
     * Configure context injection from prior phase results.
     */
    public function withContextInjection(
        bool $enabled = true,
        int $maxTokensPerPhase = 2000,
        int $maxTotalTokens = 8000,
        string $strategy = 'summary',
    ): static {
        $this->contextInjectionEnabled = $enabled;
        if ($enabled) {
            $this->contextInjector = new PhaseContextInjector(
                maxSummaryTokens: $maxTokensPerPhase,
                maxTotalTokens: $maxTotalTokens,
                strategy: $strategy,
            );
        } else {
            $this->contextInjector = null;
        }
        return $this;
    }

    /**
     * Disable context injection for this phase.
     */
    public function withoutContextInjection(): static
    {
        $this->contextInjectionEnabled = false;
        $this->contextInjector = null;
        return $this;
    }

    /**
     * Get the context injector (returns default if enabled but not explicitly configured).
     */
    public function getContextInjector(): ?PhaseContextInjector
    {
        if (!$this->contextInjectionEnabled) {
            return null;
        }
        return $this->contextInjector ?? new PhaseContextInjector();
    }

    public function isContextInjectionEnabled(): bool
    {
        return $this->contextInjectionEnabled;
    }

    // ── Retry configuration ─────────────────────────────────────

    /**
     * Set default retry policy for all agents in this phase.
     */
    public function withRetryPolicy(AgentRetryPolicy $policy): static
    {
        $this->retryPolicy = $policy;
        return $this;
    }

    /**
     * Override retry policy for a specific agent (by name).
     */
    public function withAgentRetryPolicy(string $agentName, AgentRetryPolicy $policy): static
    {
        $this->agentRetryPolicies[$agentName] = $policy;
        return $this;
    }

    /**
     * Get the effective retry policy for an agent.
     */
    public function getRetryPolicyFor(string $agentName): AgentRetryPolicy
    {
        return $this->agentRetryPolicies[$agentName]
            ?? $this->retryPolicy
            ?? AgentRetryPolicy::default();
    }

    public function getRetryPolicy(): ?AgentRetryPolicy
    {
        return $this->retryPolicy;
    }
}
