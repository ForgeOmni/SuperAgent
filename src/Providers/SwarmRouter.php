<?php

declare(strict_types=1);

namespace SuperAgent\Providers;

use SuperAgent\Providers\Capabilities\SupportsSwarm;

/**
 * Plan a multi-agent "swarm" execution by picking the best-matched
 * strategy for the given request.
 *
 * Three strategies, tried in priority order:
 *
 *   1. `native_swarm`  — dispatch to a provider that implements
 *                        `SupportsSwarm` (Kimi K2.6). Used when the caller
 *                        prefers a vendor-managed swarm, or when the
 *                        request asks for more sub-agents / steps than
 *                        SuperAgent's local infrastructure is happy with.
 *
 *   2. `agent_teams`   — route as a single chat call to a provider whose
 *                        model has native multi-agent training (MiniMax
 *                        M2.7) with the `agent_teams` feature enabled.
 *                        Cheaper and simpler than a full swarm; good fit
 *                        for tasks that need role-separation but not
 *                        hundreds of sub-agents.
 *
 *   3. `local_swarm`   — fall back to SuperAgent's in-process swarm (the
 *                        `src/Swarm/` infrastructure: Team, AgentPool,
 *                        ParallelAgentCoordinator). Works everywhere but
 *                        coordination quality depends on the base LLM.
 *
 * The router does NOT execute — it returns a `SwarmPlan` describing what
 * the caller should do. This keeps the policy decision separate from the
 * IO-heavy execution paths, which is easier to test.
 */
final class SwarmRouter
{
    /**
     * @param array<string, mixed> $request Shape:
     *   [
     *     'prompt'        => '…',               // required
     *     'provider'      => 'kimi'|null,        // explicit preference
     *     'strategy'      => 'native_swarm'|'agent_teams'|'local_swarm'|null,
     *     'max_sub_agents'=> 300|null,          // used to upgrade to native_swarm
     *     'roles'         => [...],             // if present → bias toward agent_teams
     *   ]
     */
    public static function plan(array $request): SwarmPlan
    {
        $prompt = (string) ($request['prompt'] ?? '');
        if ($prompt === '') {
            throw new \InvalidArgumentException('SwarmRouter::plan() requires a prompt');
        }

        // Explicit strategy pin wins — the caller knows what they want.
        $forced = $request['strategy'] ?? null;
        if (is_string($forced) && in_array($forced, ['native_swarm', 'agent_teams', 'local_swarm'], true)) {
            return self::build($forced, $request);
        }

        // If caller pinned a provider, honour the strategy that provider supports.
        $provider = $request['provider'] ?? null;
        if (is_string($provider)) {
            if ($provider === 'kimi') {
                return self::build('native_swarm', $request);
            }
            if ($provider === 'minimax') {
                return self::build('agent_teams', $request);
            }
        }

        // Big jobs that want many sub-agents → prefer native swarm when Kimi
        // is reachable. "Big" here is deliberately simple — tune with
        // telemetry later.
        $maxAgents = (int) ($request['max_sub_agents'] ?? 0);
        if ($maxAgents >= 20 && self::providerRegistered('kimi')) {
            return self::build('native_swarm', $request);
        }

        // Role-heavy requests fit agent_teams well.
        if (! empty($request['roles']) && self::providerRegistered('minimax')) {
            return self::build('agent_teams', $request);
        }

        // Default fallback — SuperAgent's in-process swarm.
        return self::build('local_swarm', $request);
    }

    /**
     * Is this provider both registered AND does the catalog contain at
     * least one model for it? "Registered but empty" happens during
     * tests with cleared catalogs.
     */
    private static function providerRegistered(string $name): bool
    {
        if (! ProviderRegistry::hasProvider($name)) {
            return false;
        }
        return ModelCatalog::modelsFor($name) !== [];
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function build(string $strategy, array $request): SwarmPlan
    {
        return new SwarmPlan(
            strategy: $strategy,
            provider: match ($strategy) {
                'native_swarm' => 'kimi',
                'agent_teams'  => 'minimax',
                default        => null,
            },
            prompt: (string) $request['prompt'],
            options: $request,
            rationale: self::rationale($strategy, $request),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function rationale(string $strategy, array $request): string
    {
        if (isset($request['strategy'])) {
            return "caller-forced strategy: {$strategy}";
        }
        if (isset($request['provider'])) {
            return "caller-pinned provider: {$request['provider']}";
        }
        return match ($strategy) {
            'native_swarm' => sprintf(
                'max_sub_agents=%d exceeds local threshold; Kimi native swarm selected',
                (int) ($request['max_sub_agents'] ?? 0),
            ),
            'agent_teams' => sprintf(
                '%d roles declared; MiniMax agent_teams selected',
                count($request['roles'] ?? []),
            ),
            'local_swarm' => 'no native strategy matched; falling back to SuperAgent local swarm',
            default       => $strategy,
        };
    }
}

/**
 * Routing output — pure data, no IO.
 */
final class SwarmPlan
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $strategy,
        public readonly ?string $provider,
        public readonly string $prompt,
        public readonly array $options,
        public readonly string $rationale,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy'  => $this->strategy,
            'provider'  => $this->provider,
            'prompt'    => $this->prompt,
            'options'   => $this->options,
            'rationale' => $this->rationale,
        ];
    }
}
