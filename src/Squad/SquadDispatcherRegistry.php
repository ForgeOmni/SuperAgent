<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * Process-scoped extension point for the default squad dispatcher.
 *
 * Every in-SDK code path that constructs a `PeerOrchestrator` with an
 * inline default dispatcher (`AutoMode\AutoModeAgent::runSquad`, plus
 * any future entry point added to this list) consults this registry
 * BEFORE falling back to its built-in default. A host application can
 * `set()` its own dispatcher once at boot and every default squad
 * spawn — including the bundled `superagent auto --squad` CLI —
 * routes through the host's logic without that host having to monkey-
 * patch SuperAgent or pass per-call config.
 *
 * Why a process-scoped static instead of constructor injection:
 *
 *   - `AutoModeAgent` is constructed by SDK end-users (`new AutoModeAgent(...)`)
 *     all over their codebases. Adding a required argument would be a
 *     breaking change; adding an optional one means hosts still have
 *     to thread it through every entry point. The registry side-steps
 *     that — it's a one-line opt-in at boot.
 *   - Hosts already do similar one-shot wiring elsewhere (`ModelCatalog::register()`,
 *     `AgentDepthGuard::setMax()`, `CostCalculator::register()`).
 *     Same pattern, same lifetime semantics.
 *
 * Semantics:
 *
 *   - Per-call `config['squad']['dispatcher']` ALWAYS wins. This
 *     registry is the *next-tier* default, not an override.
 *   - When no host has registered, the SDK's inline default fires —
 *     identical behaviour to pre-registry releases.
 *   - The slot holds at most one dispatcher. A second `set()` replaces
 *     the previous one (idempotent). `clear()` removes it; mainly
 *     useful for test isolation.
 *
 * The registry is intentionally narrow: a single callable. Hosts that
 * need richer wiring (per-provider routing, fallback chains, cost
 * accounting) build that *inside* their registered callable — keeping
 * SuperAgent's surface area minimal.
 *
 * @phpstan-type SquadDispatcher callable(SquadDispatchRequest): mixed
 */
final class SquadDispatcherRegistry
{
    /** @var (callable(SquadDispatchRequest): mixed)|null */
    private static $dispatcher = null;

    /**
     * Install (or replace) the default squad dispatcher. Pass null to
     * un-install — equivalent to `clear()`.
     *
     * The callable receives a `SquadDispatchRequest` and must return
     * either a plain string (the step output) OR a tuple shape
     * `['output' => string, 'cost_usd' => float, 'blackboard' => ?array]`.
     * See `PeerOrchestrator` for the exact contract.
     */
    public static function set(?callable $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * Return the registered dispatcher, or null when no host has
     * installed one. SDK code paths that build a default dispatcher
     * call this and only construct their fallback when null is
     * returned.
     *
     * @return (callable(SquadDispatchRequest): mixed)|null
     */
    public static function get(): ?callable
    {
        return self::$dispatcher;
    }

    /**
     * Whether a host has installed a default dispatcher.
     */
    public static function has(): bool
    {
        return self::$dispatcher !== null;
    }

    /**
     * Drop the registered dispatcher. Mostly useful for test isolation —
     * production code should call `set(null)` instead so the intent is
     * clear at the call site.
     */
    public static function clear(): void
    {
        self::$dispatcher = null;
    }
}
