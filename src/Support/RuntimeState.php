<?php

declare(strict_types=1);

namespace SuperAgent\Support;

use SuperAgent\Providers\ProviderRegistry;
use SuperAgent\Telemetry\CostTracker;
use SuperAgent\Telemetry\EventDispatcher;
use SuperAgent\Telemetry\MetricsCollector;
use SuperAgent\Tools\Builtin\EnterPlanModeTool;
use SuperAgent\Tools\Builtin\VerifyPlanExecutionTool;
use SuperAgent\Tracing\TraceCollector;

/**
 * Process-wide state, and how to leave none of it behind.
 *
 * Every static in this SDK was written for a CLI: one process, one person,
 * one workspace, and the process exits when they are done. A queue worker
 * that serves many tenants in one long-lived process breaks all four
 * assumptions — the statics survive, and so does whatever they accumulated
 * about the last tenant.
 *
 * Two kinds of static, and only one of them is a problem:
 *
 *   **Catalogue** — model prices, provider class names, model aliases,
 *   feature flags. The same for every tenant, expensive to rebuild, and
 *   holding nobody's data. Left alone deliberately: clearing it between jobs
 *   would re-read config files for no benefit.
 *
 *   **Accumulated** — costs, metrics, event history, cached provider
 *   instances (each holding the credentials it was built with), plan-mode
 *   state shared by tools. This is per-tenant data with process lifetime, and
 *   {@see resetPerTenant()} is what clears it.
 *
 * A worker calls it between jobs:
 *
 *     RuntimeState::resetPerTenant();
 *     $result = $agent->run($prompt);
 *
 * It is not called automatically: a CLI would pay for it on every turn to
 * solve a problem it does not have, and only the host knows where one
 * tenant's work ends and the next begins.
 *
 * @since 1.5.0
 */
final class RuntimeState
{
    /**
     * Clear everything that accumulated about one tenant's work.
     *
     * Safe to call when nothing is in flight. Never mid-turn: the cost
     * tracker and the plan-mode tools are read by a run that is still going.
     */
    public static function resetPerTenant(): void
    {
        // Provider instances cache their credentials for the life of the
        // process; the cache key includes the config, so no tenant gets
        // another's client, but every tenant's key stays in memory until this
        // runs.
        ProviderRegistry::clearCache();

        // Singletons that accumulate: a summary over all of them answers for
        // the process, not for the tenant who asked.
        CostTracker::clear();
        MetricsCollector::clear();
        EventDispatcher::clear();

        // Plan mode is shared tool state: one tenant's plan would otherwise
        // still be "the current plan" for the next.
        EnterPlanModeTool::reset();
        VerifyPlanExecutionTool::reset();

        // Trace ring buffer: diagnostics for the turn that just ended.
        TraceCollector::setInstance(null);
    }

    /**
     * What {@see resetPerTenant()} clears, and what it deliberately does not —
     * the inventory a host can assert against when this SDK adds a static.
     *
     * @return array{cleared: list<string>, kept: array<string,string>}
     */
    public static function inventory(): array
    {
        return [
            'cleared' => [
                ProviderRegistry::class . '::$instances',
                ProviderRegistry::class . '::$credentialPool',
                CostTracker::class . '::$instance',
                MetricsCollector::class . '::$instance',
                EventDispatcher::class . '::$instance',
                EnterPlanModeTool::class . '::$sharedState',
                VerifyPlanExecutionTool::class . '::$sharedState',
                TraceCollector::class . '::$instance',
            ],
            'kept' => [
                'SuperAgent\Providers\ModelCatalog' => 'Model prices and ids. Identical for every tenant.',
                'SuperAgent\Providers\ModelResolver' => 'Alias table built from config, not from tenant data.',
                'SuperAgent\Config\FeatureFlags' => 'Process configuration.',
                'SuperAgent\Tools\ToolNameResolver' => 'Static name mapping.',
            ],
        ];
    }
}
