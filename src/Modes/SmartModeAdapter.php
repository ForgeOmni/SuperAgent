<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

use SuperAgent\Evals\SmartOrchestrator;

/**
 * Thin adapter making `SmartOrchestrator` callable through the
 * cross-mode `ModeOrchestrator` contract.
 *
 * Maps:
 *   - `$task` → `SmartOrchestrator::run($task)`
 *   - cost   → `costLedger->record('smart', $totalCostUsd)`
 *   - return → ModeResult with the `subtask_results` shape preserved
 *              under `modeSpecific` for hosts that want to render
 *              the routing table.
 */
final class SmartModeAdapter implements ModeOrchestrator
{
    public function __construct(
        private readonly SmartOrchestrator $inner,
    ) {}

    public function modeName(): string
    {
        return 'smart';
    }

    public function execute(string $task, ModeContext $context, array $options = []): ModeResult
    {
        $r = $this->inner->run($task);
        $cost = (float) ($r['total_cost_usd'] ?? 0.0);
        $context->costLedger->record('smart', $cost);
        return new ModeResult(
            text:    (string) ($r['final'] ?? ''),
            costUsd: $cost,
            mode:    'smart',
            trace:   $context->modeStack,
            modeSpecific: [
                'plan'             => $r['plan'] ?? [],
                'brain'            => $r['brain'] ?? null,
                'subtask_results'  => $r['subtask_results'] ?? [],
                'total_latency_ms' => $r['total_latency_ms'] ?? 0,
                'run_log_path'     => $r['run_log_path'] ?? null,
            ],
        );
    }
}
