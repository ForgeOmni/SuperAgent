<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Pipeline\StepStatus;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\ModelTierMap;
use SuperAgent\Squad\PeerOrchestrator;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAgent\Squad\SquadResumeManager;
use SuperAgent\Squad\SubTask;

class PeerOrchestratorTest extends TestCase
{
    public function test_run_executes_each_subtask_on_its_pinned_model(): void
    {
        $subTasks = [
            new SubTask(
                name: 'research-01',
                role: 'research',
                prompt: 'Survey the auth landscape',
                difficulty: DifficultyClass::TRIVIAL,
            ),
            new SubTask(
                name: 'design-02',
                role: 'design',
                prompt: 'Design migration plan',
                difficulty: DifficultyClass::EXPERT,
                dependsOn: ['research-01'],
            ),
        ];

        $dispatchedWith = [];
        $dispatcher = function (SquadDispatchRequest $req) use (&$dispatchedWith) {
            $dispatchedWith[$req->role->name] = [
                'provider' => $req->provider,
                'model'    => $req->model,
                'tier'     => $req->role->tier->value,
                'session'  => $req->sessionId,
            ];
            return "ok-{$req->role->name}";
        };

        $orchestrator = new PeerOrchestrator($dispatcher);
        $result = $orchestrator->run('test-squad', $subTasks);

        $this->assertTrue($result->isSuccessful());

        // Each step ran on a model from a different difficulty band.
        $defaults = ModelTierMap::defaults();
        $this->assertSame(
            $defaults[DifficultyClass::TRIVIAL->value]['model'],
            $dispatchedWith['research-01']['model']
        );
        $this->assertSame(
            $defaults[DifficultyClass::EXPERT->value]['model'],
            $dispatchedWith['design-02']['model']
        );
    }

    public function test_session_id_is_stable_for_the_same_role(): void
    {
        $subTasks = [new SubTask('execute-01', 'execute', 'go', DifficultyClass::MODERATE)];

        $sessions = [];
        $dispatcher = function (SquadDispatchRequest $req) use (&$sessions) {
            $sessions[] = $req->sessionId;
            return 'ok';
        };

        $orchestrator = new PeerOrchestrator($dispatcher);
        $orchestrator->run('squad-A', $subTasks);
        $first = $sessions[0];

        $orchestrator->run('squad-A', $subTasks);
        $second = $sessions[1];

        // Same squad id + role → same session id → KV cache reuse.
        $this->assertSame($first, $second);
    }

    public function test_resume_skips_steps_with_existing_results(): void
    {
        $subTasks = [
            new SubTask('a', 'execute', 'A', DifficultyClass::EASY),
            new SubTask('b', 'execute', 'B', DifficultyClass::MODERATE, dependsOn: ['a']),
        ];

        $dispatcher = fn (SquadDispatchRequest $req) => "real-{$req->role->name}";
        $first = (new PeerOrchestrator($dispatcher))->run('squad-resume', $subTasks);
        $this->assertTrue($first->isSuccessful());

        // Resume: ask to skip step "a" — only "b" should be dispatched.
        $manager = new SquadResumeManager();
        $preSeed = $manager->buildPreSeed($subTasks, $first, skipSteps: ['a']);

        $this->assertArrayHasKey('a', $preSeed);
        $this->assertSame('real-a', $preSeed['a']['output']);
    }

    public function test_resume_from_step_invalidates_descendants(): void
    {
        $subTasks = [
            new SubTask('research-01', 'research', 'A', DifficultyClass::EASY),
            new SubTask('design-02',   'design',   'B', DifficultyClass::HARD, dependsOn: ['research-01']),
            new SubTask('verify-03',   'verify',   'C', DifficultyClass::MODERATE, dependsOn: ['design-02']),
        ];

        $dispatcher = fn (SquadDispatchRequest $req) => "ok-{$req->role->name}";
        $first = (new PeerOrchestrator($dispatcher))->run('squad-cascade', $subTasks);

        $manager = new SquadResumeManager();
        $preSeed = $manager->buildPreSeed($subTasks, $first, fromStep: 'design-02');

        // research-01 was upstream of design-02 → can be reused
        $this->assertArrayHasKey('research-01', $preSeed);
        // design-02 itself is being restarted → not reusable
        $this->assertArrayNotHasKey('design-02', $preSeed);
        // verify-03 transitively depends on the restart → not reusable
        $this->assertArrayNotHasKey('verify-03', $preSeed);
    }
}
