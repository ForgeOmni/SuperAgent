<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

/**
 * The single interface every cross-mode-aware orchestrator implements.
 *
 * SDK provides three implementations (`AutoModeAgent`,
 * `SmartOrchestrator`, `PeerOrchestrator` — adapted via small
 * wrappers when needed). Hosts can register their own
 * implementations (e.g. SuperAICore's `CliAutoMode` /
 * `CliSmartOrchestrator` / `CliSquadOrchestrator`) and route through
 * the shared `ModeRouter`.
 *
 * Contract:
 *
 *   - Implementations MUST accept a `ModeContext` and use its
 *     `descend()` when recursing into another mode.
 *   - Implementations MUST record every leaf model dispatch's cost
 *     into `context->costLedger`.
 *   - Implementations SHOULD write structured findings to the
 *     shared blackboard so a parent mode can read them back.
 *   - Implementations MUST return a `ModeResult` with `mode` set to
 *     their own mode name (`'auto'`, `'smart'`, `'squad'`, etc.).
 */
interface ModeOrchestrator
{
    /**
     * Execute the mode on a task within the given cross-mode context.
     *
     * Named `execute()` rather than `run()` to avoid clashing with
     * existing orchestrator classes whose `run()` predates this
     * interface (`AutoModeAgent::run($prompt, $options)`,
     * `SmartOrchestrator::run($task)`, etc.). Each
     * `ModeOrchestrator` implementation typically delegates from
     * `execute()` to its native `run()` after translating
     * arguments and threading the `ModeContext` through.
     *
     * @param array<string, mixed> $options Mode-specific options
     *                                      (tier_map, plan, etc.).
     */
    public function execute(string $task, ModeContext $context, array $options = []): ModeResult;

    /**
     * The mode's stable identifier (`'auto'` / `'smart'` / `'squad'`).
     * Used by `ModeRouter` to dispatch and by `ModeContext::descend()`
     * to advance the mode stack.
     */
    public function modeName(): string;
}
