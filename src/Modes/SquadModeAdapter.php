<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

use SuperAgent\Pipeline\StepStatus;
use SuperAgent\Squad\ModelTierMap;
use SuperAgent\Squad\PeerOrchestrator;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAgent\Squad\SquadDispatcherRegistry;
use SuperAgent\Squad\SquadPlan;
use SuperAgent\Squad\SubTask;
use SuperAgent\Squad\TaskDecomposer;

/**
 * Adapter making `PeerOrchestrator` callable through the cross-mode
 * `ModeOrchestrator` contract. Unlike Auto/Smart adapters — which
 * just wrap an existing orchestrator instance — Squad needs more
 * work: we must build a fresh `PeerOrchestrator` per call with the
 * right `agentDispatcher` (host-registered via
 * `SquadDispatcherRegistry` or the SDK default), threaded through
 * the cross-mode context.
 *
 * Options it consumes (mirrors `CliSquadOrchestrator` in SuperAICore):
 *   - `plan`           : `SquadPlan`  — pre-built team (subtasks/loops/tierMap)
 *   - `subtasks`       : `array`       — raw subtasks (if no plan)
 *   - `tier_map`       : `array`       — band→{provider, model}
 *   - `inputs`         : `array`       — pipeline inputs
 *   - `squad_id`       : `string`      — stable id for resume/checkpoint
 *
 * Cost accounting: every `agentDispatcher` invocation can return
 * `cost_usd` in its tuple shape; we accumulate into the shared
 * ledger before returning.
 */
final class SquadModeAdapter implements ModeOrchestrator
{
    public function modeName(): string
    {
        return 'squad';
    }

    public function execute(string $task, ModeContext $context, array $options = []): ModeResult
    {
        if (!class_exists(PeerOrchestrator::class)) {
            return new ModeResult(text: '', costUsd: 0.0, mode: 'squad', trace: $context->modeStack);
        }

        // Resolve plan or fall back to heuristic decomposition.
        $plan = $options['plan'] ?? null;
        if ($plan instanceof SquadPlan) {
            $subtasks = $plan->subTasks;
            $tierMap  = $this->tierMapFromPlan($plan);
        } else {
            $subtasks = $this->resolveSubtasks($options, $task);
            $tierMap  = $this->resolveTierMap($options);
        }

        // Outer dispatcher: prefer host-registered via SPI, fall back
        // to SDK's inline default. Wrap so cost accumulates.
        $base = SquadDispatcherRegistry::get();
        if ($base === null) {
            $base = $this->buildSdkFallbackDispatcher();
        }
        $wrapped = function (SquadDispatchRequest $req) use ($base, $context) {
            $r = $base($req);
            if (is_array($r) && isset($r['cost_usd'])) {
                $context->costLedger->record('squad', (float) $r['cost_usd'], $req->role->name, $req->model);
            }
            return $r;
        };

        $squadId = (string) ($options['squad_id'] ?? 'sq_' . bin2hex(random_bytes(6)));
        $orchestrator = new PeerOrchestrator(
            agentDispatcher: $wrapped,
        );
        $result = $orchestrator->run(
            squadId:  $squadId,
            subTasks: $subtasks,
            tierMap:  $tierMap,
            inputs:   (array) ($options['inputs'] ?? []),
        );

        $text = $this->extractFinalText($result);
        $completed = $result->completedStepNames();
        $rolesOut = [];
        foreach ($result->roles as $role) {
            $rolesOut[] = [
                'name'     => $role->name,
                'provider' => $role->provider,
                'model'    => $role->model,
                'tier'     => $role->tier->value,
            ];
        }

        return new ModeResult(
            text:    $text,
            costUsd: $context->costLedger->byMode()['squad'] ?? 0.0,
            mode:    'squad',
            trace:   $context->modeStack,
            modeSpecific: [
                'squad_id'   => $squadId,
                'completed'  => $completed,
                'roles'      => $rolesOut,
                'mailbox_log' => $result->mailbox !== null
                    ? array_map(fn($m) => method_exists($m, 'toArray') ? $m->toArray() : (array) $m, $result->mailbox->log())
                    : [],
            ],
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return SubTask[]
     */
    private function resolveSubtasks(array $options, string $task): array
    {
        if (isset($options['subtasks']) && is_array($options['subtasks']) && $options['subtasks'] !== []) {
            $out = [];
            foreach ($options['subtasks'] as $st) {
                if ($st instanceof SubTask) { $out[] = $st; continue; }
            }
            if ($out !== []) return $out;
        }
        return (new TaskDecomposer())->decompose($task);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolveTierMap(array $options): ModelTierMap
    {
        $raw = $options['tier_map'] ?? null;
        if (!is_array($raw) || $raw === []) return new ModelTierMap();
        $map = new ModelTierMap();
        foreach ($raw as $bandKey => $entry) {
            $band = \SuperAgent\Squad\DifficultyClass::tryFrom((string) $bandKey);
            if ($band === null || !is_array($entry)) continue;
            $p = (string) ($entry['provider'] ?? '');
            $m = (string) ($entry['model'] ?? '');
            if ($p === '' || $m === '') continue;
            $map = $map->with($band, $p, $m);
        }
        return $map;
    }

    private function tierMapFromPlan(SquadPlan $plan): ModelTierMap
    {
        $map = new ModelTierMap();
        foreach ($plan->tierMap as $bandKey => $entry) {
            $band = \SuperAgent\Squad\DifficultyClass::tryFrom((string) $bandKey);
            if ($band === null) continue;
            $p = (string) ($entry['provider'] ?? '');
            $m = (string) ($entry['model'] ?? '');
            if ($p === '' || $m === '') continue;
            $map = $map->with($band, $p, $m);
        }
        return $map;
    }

    /**
     * Default fallback dispatcher when no host registered one. Mirrors
     * `AutoModeAgent::buildDefaultSquadDispatcher()` — fresh chat
     * Agent per step, no tool loop, no peer messaging.
     */
    private function buildSdkFallbackDispatcher(): callable
    {
        return static function (SquadDispatchRequest $req) {
            $ctx = new \SuperAgent\Context\Context();
            if ($req->provider !== '') {
                $ctx->setMetadata('provider', $req->provider);
            }
            if ($req->sessionId !== null && $req->sessionId !== '') {
                $ctx->setMetadata('session_id', $req->sessionId);
            }
            $ctx->setMetadata('squad_role', $req->role->name);
            $agent = new \SuperAgent\Agent\Agent(context: $ctx);
            if ($req->model !== '') {
                $agent->setModel($req->model);
            }
            if ($req->systemPrompt !== null && $req->systemPrompt !== '') {
                $agent->setSystemPrompt($req->systemPrompt);
            }
            $r = $agent->run($req->prompt);
            return [
                'output'   => $r->text(),
                'cost_usd' => (float) ($r->totalCostUsd ?? 0.0),
            ];
        };
    }

    /**
     * @param object $squadResult `SquadResult`
     */
    private function extractFinalText(object $squadResult): string
    {
        $pipelineResult = $squadResult->pipelineResult ?? null;
        if ($pipelineResult === null) return '';
        $results = $pipelineResult->getStepResults();
        if ($results === []) return '';
        $last = null;
        foreach ($results as $r) {
            if ($r->status !== StepStatus::COMPLETED) continue;
            $last = $r;
        }
        return $last !== null ? (string) ($last->output ?? '') : '';
    }
}
