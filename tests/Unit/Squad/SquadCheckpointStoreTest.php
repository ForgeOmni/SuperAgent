<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\PeerOrchestrator;
use SuperAgent\Squad\SquadCheckpointStore;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAgent\Squad\SubTask;

class SquadCheckpointStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/squad-cp-test-' . uniqid();
        @mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function test_record_step_persists_and_load_returns_state(): void
    {
        $store = new SquadCheckpointStore($this->tmpDir);
        $store->recordStep('squad-1', 'research-01', 'survey result', 'completed');
        $store->recordStep('squad-1', 'design-02', ['plan' => 'OAuth2'], 'completed');

        $loaded = $store->load('squad-1');
        $this->assertNotNull($loaded);
        $this->assertSame('survey result', $loaded['steps']['research-01']['output']);
        $this->assertSame(['plan' => 'OAuth2'], $loaded['steps']['design-02']['output']);
    }

    public function test_load_returns_null_when_no_checkpoint_yet(): void
    {
        $store = new SquadCheckpointStore($this->tmpDir);
        $this->assertNull($store->load('never-existed'));
    }

    public function test_orchestrator_rehydrates_from_checkpoint_store(): void
    {
        $store = new SquadCheckpointStore($this->tmpDir);
        // Simulate a prior partial run that completed step "a".
        $store->recordStep('squad-resume', 'a', 'cached-a', 'completed');

        $subTasks = [
            new SubTask('a', 'execute', 'do A', DifficultyClass::EASY),
            new SubTask('b', 'execute', 'do B', DifficultyClass::MODERATE, dependsOn: ['a']),
        ];

        $dispatched = [];
        $dispatcher = function (SquadDispatchRequest $req) use (&$dispatched) {
            $dispatched[] = $req->role->name;
            return "fresh-{$req->role->name}";
        };

        $orchestrator = new PeerOrchestrator($dispatcher, null, checkpointStore: $store);
        $result = $orchestrator->run('squad-resume', $subTasks);

        // 'a' should NOT have been dispatched again — its output came from disk.
        $this->assertNotContains('a', $dispatched);
        $this->assertContains('b', $dispatched);
        $this->assertTrue($result->isSuccessful());
    }
}
