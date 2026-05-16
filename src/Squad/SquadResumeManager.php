<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use SuperAgent\Pipeline\StepResult;
use SuperAgent\Pipeline\StepStatus;

/**
 * Re-runs a Squad from a chosen step while keeping prior step
 * outputs intact.
 *
 * Two scenarios it supports:
 *
 *   - "Skip step": user re-runs the same Squad with one or more
 *     completed steps already done — those steps are flagged as
 *     `_prefilled_steps` and the orchestrator hands their output
 *     back to downstream steps untouched.
 *
 *   - "Restart from step": user found a problem N steps in. The
 *     manager preserves results for the predecessor chain and clears
 *     `$fromStep` plus every step that transitively depends on it,
 *     then hands the truncated list back to the orchestrator.
 *
 * Because the orchestrator builds each role's session ID from
 * `squadId + roleName`, restarting a step reuses the same provider
 * session — so the model's prompt cache and prior assistant
 * messages stay warm. No re-priming, no re-paying for the same
 * cached prefix.
 */
final class SquadResumeManager
{
    /**
     * Return the set of prior step outputs that should be re-injected
     * into the orchestrator's `preSeededStepOutputs` parameter.
     *
     * @param SubTask[] $subTasks    The same plan, decomposed again.
     * @param SquadResult $prior     Result of an earlier run.
     * @param string[] $skipSteps    Names the caller has explicitly
     *                               marked as "still valid, don't re-run".
     * @param string|null $fromStep  If set, every step at or after this
     *                               (and its transitive descendants)
     *                               will NOT be skipped — it will run
     *                               again with prior context preserved.
     * @return array<string, array{output: mixed, status: string}>
     */
    public function buildPreSeed(
        array $subTasks,
        SquadResult $prior,
        array $skipSteps = [],
        ?string $fromStep = null,
    ): array {
        $invalidated = $fromStep !== null
            ? $this->collectDescendants($subTasks, $fromStep)
            : [];

        $reusableNames = $prior->completedStepNames();
        if (!empty($skipSteps)) {
            $reusableNames = array_values(array_intersect($reusableNames, $skipSteps));
        }
        $reusableNames = array_values(array_diff($reusableNames, $invalidated));

        $seed = [];
        foreach ($prior->pipelineResult->getStepResults() as $r) {
            if (!in_array($r->stepName, $reusableNames, true)) {
                continue;
            }
            $seed[$r->stepName] = [
                'output' => $r->output,
                'status' => $r->status->value,
            ];
        }

        return $seed;
    }

    /**
     * Mutate a list of sub-tasks to inject `_prefilled` markers so the
     * orchestrator can short-circuit them and just expose their cached
     * output via the pipeline context.
     *
     * NB this is intentionally a side-channel: the SubTask itself is
     * immutable, so we publish the marker on the `inputs` map the
     * caller passes to `PeerOrchestrator::run()` (see preSeededStepOutputs).
     *
     * @param SubTask[] $subTasks
     * @return string[] Names that are walkable on this resume.
     */
    public function walkableStepNames(array $subTasks, array $preSeed): array
    {
        $names = [];
        foreach ($subTasks as $s) {
            if (!array_key_exists($s->name, $preSeed)) {
                $names[] = $s->name;
            }
        }
        return $names;
    }

    /**
     * BFS the dependency graph and collect every step that needs to
     * re-run because it depends on `$fromStep` (or is `$fromStep`).
     *
     * @param SubTask[] $subTasks
     * @return string[]
     */
    private function collectDescendants(array $subTasks, string $fromStep): array
    {
        $byName = [];
        foreach ($subTasks as $s) {
            $byName[$s->name] = $s;
        }

        if (!isset($byName[$fromStep])) {
            return [$fromStep];
        }

        $invalidated = [$fromStep => true];

        // Iteratively flag anything that depends on an already-invalid step.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($subTasks as $s) {
                if (isset($invalidated[$s->name])) {
                    continue;
                }
                foreach ($s->dependsOn as $dep) {
                    if (isset($invalidated[$dep])) {
                        $invalidated[$s->name] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return array_keys($invalidated);
    }
}
