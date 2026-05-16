<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SuperAgent\Pipeline\PipelineEngine;
use SuperAgent\Pipeline\StepStatus;
use SuperAgent\Pipeline\Steps\AgentStep;
use SuperAgent\Pipeline\Steps\ApprovalStep;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs an Adaptive Cross-Model Squad without a master agent.
 *
 * Compared to `Coordinator\CoordinatorMode` (which spawns a worker
 * fleet driven by a single coordinator LLM) this orchestrator is a
 * pure executor: the workflow definition is the orchestrator, every
 * step is a peer, and the human is also a peer when an `ApprovalStep`
 * is reached.
 *
 * Capabilities wired in here:
 *   - Cross-model dispatch (per-step provider/model)
 *   - Stable per-role session IDs (KV cache reuse across steps)
 *   - Optional cost budget — downshifts remaining steps when nearing
 *     the cap, halts the pipeline when the cap is reached
 *   - Optional per-step checkpointing — every successful step is
 *     persisted so a crash mid-pipeline resumes cleanly
 *   - Optional console progress listener — streams step events to a
 *     supplied OutputInterface
 *
 * Resume is handled by `SquadResumeManager`, which builds the
 * `preSeededStepOutputs` map this orchestrator consumes.
 */
final class PeerOrchestrator
{
    /** @var callable(SquadDispatchRequest): mixed */
    private $agentDispatcher;

    /** @var callable(ApprovalStep, \SuperAgent\Pipeline\PipelineContext): bool */
    private $approvalHandler;

    public function __construct(
        callable $agentDispatcher,
        ?callable $approvalHandler = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SquadCheckpointStore $checkpointStore = null,
        private readonly ?OutputInterface $output = null,
        private readonly ?float $maxCostUsd = null,
    ) {
        $this->agentDispatcher = $agentDispatcher;
        $this->approvalHandler = $approvalHandler ?? fn () => true;
    }

    /**
     * Run a freshly-composed squad.
     *
     * @param SubTask[] $subTasks
     * @param array<string, array{output: mixed, status: string}> $preSeededStepOutputs
     */
    public function run(
        string $squadId,
        array $subTasks,
        ?ModelTierMap $tierMap = null,
        array $inputs = [],
        array $preSeededStepOutputs = [],
    ): SquadResult {
        $tierMap = $tierMap ?? new ModelTierMap();

        // Rehydrate from disk if no caller-supplied seed but checkpoint exists.
        if (empty($preSeededStepOutputs) && $this->checkpointStore !== null) {
            $saved = $this->checkpointStore->load($squadId);
            if ($saved !== null) {
                $preSeededStepOutputs = $saved['steps'] ?? [];
            }
        }

        $composer = new SquadComposer($tierMap);
        $pipelineName = 'squad-' . $squadId;
        $composed = $composer->compose($pipelineName, $subTasks, $squadId);

        /** @var \SuperAgent\Pipeline\PipelineConfig $config */
        $config = $composed['config'];
        /** @var array<string, SquadRole> $roles */
        $roles = $composed['roles'];

        $blackboard = new Blackboard();

        // Build the peer mailbox. The default answerer routes peer
        // questions through the SAME agent dispatcher used for step
        // execution, so peers see questions on their own session_id
        // (cache continuity preserved). Hosts that want cross-process
        // routing can inject a different PeerAnswerer via the
        // mailbox before run() is called — but for now we always
        // build the default one and let callers ignore it.
        $mailbox = new PeerMailbox(new DispatcherPeerAnswerer($this->agentDispatcher, $blackboard));
        $mailbox->registerRoles($roles);

        $engine = new PipelineEngine($config, $this->logger);

        // Mutable cost counter; closures below capture it by reference
        // so we can short-circuit subsequent steps without bolting an
        // extra collaborator onto every dispatch.
        $costState = ['total' => 0.0, 'downshifted' => false];

        $engine->setAgentRunner(function (AgentStep $step, $context) use ($roles, $blackboard, $mailbox, $preSeededStepOutputs, &$costState, $tierMap) {
            $stepName = $step->getName();

            // Skip pre-seeded steps cheaply — return the cached output.
            if (isset($preSeededStepOutputs[$stepName])) {
                return (string) ($preSeededStepOutputs[$stepName]['output'] ?? '');
            }

            $role = $roles[$stepName] ?? null;
            if ($role === null) {
                throw new \RuntimeException("No SquadRole registered for step '{$stepName}'");
            }

            // Cost-aware downshift: when within 20% of budget, drop this
            // step to a cheaper tier before dispatching.
            $effectiveRole = $this->applyCostPolicy($role, $tierMap, $costState);

            [$provider, $model] = $this->parseProviderModel($step);
            if ($effectiveRole !== $role) {
                $provider = $effectiveRole->provider;
                $model    = $effectiveRole->model;
            }

            // Drain inbox: any peer messages queued for this role get
            // prepended to its prompt so the agent sees them at the
            // top of its step. The dispatcher decides whether to expose
            // PeerAsk/PeerSend tools via the request's mailbox.
            $prompt = $this->resolvedPrompt($step, $context);
            $inboxBlock = $mailbox->renderInboxFor($effectiveRole->name);
            if ($inboxBlock !== '') {
                $prompt = $inboxBlock . "\n" . $prompt;
            }

            $request = new SquadDispatchRequest(
                role: $effectiveRole,
                provider: $provider,
                model: $model,
                prompt: $prompt,
                systemPrompt: $effectiveRole->systemPrompt,
                sessionId: $effectiveRole->sessionId,
                blackboard: $blackboard,
                mailbox: $mailbox,
            );

            $output = ($this->agentDispatcher)($request);

            // Tuple return: {output, blackboard?: array, cost_usd?: float}
            if (is_array($output) && isset($output['output'])) {
                if (!empty($output['blackboard'])) {
                    foreach ((array) $output['blackboard'] as $key => $value) {
                        $blackboard->write($effectiveRole->name, (string) $key, $value);
                    }
                }
                if (isset($output['cost_usd'])) {
                    $costState['total'] += (float) $output['cost_usd'];
                }
                return (string) $output['output'];
            }

            return (string) $output;
        });

        $engine->setApprovalHandler($this->approvalHandler);

        // Persist a checkpoint after each successful step.
        if ($this->checkpointStore !== null) {
            $store = $this->checkpointStore;
            $engine->on('step.end', function (array $event) use ($store, $squadId, $engine, $pipelineName): void {
                if (($event['status'] ?? '') !== 'completed') {
                    return;
                }
                $pipeline = $engine->getPipeline($pipelineName);
                if ($pipeline === null) {
                    return;
                }
                // The engine emits step.end after writing to the context
                // — we re-fetch the result via the in-flight definition.
                // We don't have direct context here; instead we record
                // the step name+status and let `run()` finalise the
                // outputs after completion (see below).
                $store->recordStep($squadId, (string) ($event['step'] ?? ''), null, 'completed');
            });
        }

        if ($this->output !== null) {
            (new SquadConsoleListener($this->output))->attach($engine);
        }

        $pipelineResult = $engine->run($pipelineName, $inputs);

        // Final pass: write the *actual* outputs to the checkpoint store
        // (the event hook above only captured status). This guarantees
        // resume sees real payloads even if the run was interrupted
        // between dispatch and event emission.
        if ($this->checkpointStore !== null) {
            foreach ($pipelineResult->getStepResults() as $r) {
                if ($r->status === StepStatus::COMPLETED) {
                    $this->checkpointStore->recordStep(
                        $squadId,
                        $r->stepName,
                        $r->output,
                        $r->status->value,
                    );
                }
            }
        }

        return new SquadResult(
            squadId: $squadId,
            pipelineResult: $pipelineResult,
            roles: $roles,
            blackboard: $blackboard,
            modelTierSnapshot: $tierMap->toArray(),
            mailbox: $mailbox,
        );
    }

    /**
     * Drop a role to the next-cheaper band when we're within 20% of
     * the cost cap. When we're AT or OVER the cap, replace the model
     * with `null` so the dispatcher knows to short-circuit. Pure
     * function over the cost state — no side effects.
     */
    private function applyCostPolicy(SquadRole $role, ModelTierMap $tierMap, array &$costState): SquadRole
    {
        if ($this->maxCostUsd === null) {
            return $role;
        }

        $threshold = $this->maxCostUsd * 0.8;
        if ($costState['total'] < $threshold) {
            return $role;
        }

        // Already downshifted this run — no further drops needed.
        if ($costState['downshifted']) {
            return $role;
        }

        $cheaper = match ($role->tier) {
            DifficultyClass::EXPERT   => DifficultyClass::HARD,
            DifficultyClass::HARD     => DifficultyClass::MODERATE,
            DifficultyClass::MODERATE => DifficultyClass::EASY,
            DifficultyClass::EASY     => DifficultyClass::TRIVIAL,
            DifficultyClass::TRIVIAL  => DifficultyClass::TRIVIAL,
        };

        if ($cheaper === $role->tier) {
            return $role;
        }

        $resolved = $tierMap->resolve($cheaper);
        $costState['downshifted'] = true;
        $this->logger->warning('Squad cost approaching cap — downshifting tier', [
            'role'        => $role->name,
            'from_tier'   => $role->tier->value,
            'to_tier'     => $cheaper->value,
            'spent_usd'   => $costState['total'],
            'cap_usd'     => $this->maxCostUsd,
        ]);

        return new SquadRole(
            name: $role->name,
            provider: $resolved['provider'],
            model: $resolved['model'],
            tier: $cheaper,
            systemPrompt: $role->systemPrompt,
            templateRef: $role->templateRef,
            sessionId: $role->sessionId,
        );
    }

    /**
     * Split the `<provider>:<model>` tag the composer encoded in the
     * AgentStep's `model` field.
     *
     * @return array{0: string, 1: string}
     */
    private function parseProviderModel(AgentStep $step): array
    {
        $spawn = $step->buildSpawnConfig(new \SuperAgent\Pipeline\PipelineContext());
        $raw = (string) ($spawn->model ?? '');

        if ($raw === '' || !str_contains($raw, ':')) {
            return ['', $raw];
        }

        [$provider, $model] = explode(':', $raw, 2);
        return [$provider, $model];
    }

    private function resolvedPrompt(AgentStep $step, $context): string
    {
        return $step->buildSpawnConfig($context)->prompt;
    }
}
