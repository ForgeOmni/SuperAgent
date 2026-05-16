<?php

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\DifficultyClass;
use SuperAgent\Squad\PeerOrchestrator;
use SuperAgent\Squad\SquadDispatchRequest;
use SuperAgent\Squad\SubTask;

class CostBudgetTest extends TestCase
{
    public function test_downshifts_remaining_steps_when_within_cap(): void
    {
        $subTasks = [
            new SubTask('a', 'execute', 'A', DifficultyClass::EXPERT),
            new SubTask('b', 'execute', 'B', DifficultyClass::EXPERT, dependsOn: ['a']),
        ];

        $tiers = [];
        $dispatcher = function (SquadDispatchRequest $req) use (&$tiers) {
            $tiers[$req->role->name] = $req->role->tier->value;
            // First step burns 80% of the budget → triggers downshift on step 2.
            return ['output' => 'ok', 'cost_usd' => 0.85];
        };

        $orchestrator = new PeerOrchestrator($dispatcher, null, maxCostUsd: 1.00);
        $orchestrator->run('squad-cost', $subTasks);

        // Step a runs at EXPERT (cost not yet over threshold)
        $this->assertSame('expert', $tiers['a']);
        // Step b runs at HARD because we crossed the 80% threshold
        $this->assertSame('hard', $tiers['b']);
    }

    public function test_no_downshift_when_under_budget(): void
    {
        $subTasks = [
            new SubTask('a', 'execute', 'A', DifficultyClass::EXPERT),
            new SubTask('b', 'execute', 'B', DifficultyClass::EXPERT, dependsOn: ['a']),
        ];

        $tiers = [];
        $dispatcher = function (SquadDispatchRequest $req) use (&$tiers) {
            $tiers[$req->role->name] = $req->role->tier->value;
            return ['output' => 'ok', 'cost_usd' => 0.01];
        };

        $orchestrator = new PeerOrchestrator($dispatcher, null, maxCostUsd: 1.00);
        $orchestrator->run('squad-cheap', $subTasks);

        $this->assertSame('expert', $tiers['a']);
        $this->assertSame('expert', $tiers['b']);
    }
}
