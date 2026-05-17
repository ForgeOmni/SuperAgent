<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

use SuperAgent\AutoMode\AutoModeAgent;

/**
 * Thin adapter making `AutoModeAgent` callable through the
 * cross-mode `ModeOrchestrator` contract.
 *
 * The adapter exists so `AutoModeAgent`'s native constructor and
 * run signature stay untouched — existing callers see no API
 * change. New callers route through `ModeRouter` and get
 * blackboard / cost-ledger threading "for free".
 *
 * Cost accounting: `AutoModeAgent::run()` returns an `AgentResult`
 * with a (possibly null) `totalCostUsd`. The adapter records that
 * single number against the `auto` mode in the shared ledger.
 * Sub-mode dispatches inside `AutoModeAgent` (currently
 * `runSquad`) record their own costs separately via SDK's own
 * dispatcher path.
 */
final class AutoModeAdapter implements ModeOrchestrator
{
    public function __construct(
        private readonly AutoModeAgent $inner,
    ) {}

    public function modeName(): string
    {
        return 'auto';
    }

    public function execute(string $task, ModeContext $context, array $options = []): ModeResult
    {
        $result = $this->inner->run($task, $options);
        $cost   = (float) ($result->totalCostUsd ?? 0.0);
        $context->costLedger->record('auto', $cost);
        return new ModeResult(
            text:        method_exists($result, 'text') ? $result->text() : (string) ($result->message?->content[0]?->text ?? ''),
            costUsd:     $cost,
            mode:        'auto',
            trace:       $context->modeStack,
            modeSpecific: [
                'turns' => method_exists($result, 'turns') ? $result->turns() : 1,
            ],
        );
    }
}
