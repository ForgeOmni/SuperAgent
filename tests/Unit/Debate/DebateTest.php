<?php

namespace SuperAgent\Tests\Unit\Debate;

use PHPUnit\Framework\TestCase;
use SuperAgent\Debate\DebateConfig;
use SuperAgent\Debate\RedTeamConfig;
use SuperAgent\Debate\EnsembleConfig;
use SuperAgent\Debate\DebateRound;
use SuperAgent\Debate\DebateResult;

class DebateTest extends TestCase
{
    // ── DebateConfig ─────────────────────────────────────────────

    public function test_config_defaults(): void
    {
        $config = DebateConfig::create();

        $this->assertEquals('opus', $config->proposerModel);
        $this->assertEquals('sonnet', $config->criticModel);
        $this->assertEquals('opus', $config->judgeModel);
        $this->assertEquals(3, $config->rounds);
        $this->assertTrue($config->allowTools);
    }

    public function test_config_fluent_api(): void
    {
        $config = DebateConfig::create()
            ->withProposerModel('haiku')
            ->withCriticModel('opus')
            ->withJudgeModel('sonnet')
            ->withRounds(5)
            ->withMaxTurnsPerRound(10)
            ->withMaxBudget(10.0)
            ->withTools(false)
            ->withJudgingCriteria('Pick the safest approach');

        $this->assertEquals('haiku', $config->proposerModel);
        $this->assertEquals('opus', $config->criticModel);
        $this->assertEquals('sonnet', $config->judgeModel);
        $this->assertEquals(5, $config->rounds);
        $this->assertEquals(10, $config->maxTurnsPerRound);
        $this->assertEquals(10.0, $config->maxBudget);
        $this->assertFalse($config->allowTools);
        $this->assertEquals('Pick the safest approach', $config->judgingCriteria);
    }

    public function test_config_with_system_prompts(): void
    {
        $config = DebateConfig::create()
            ->withProposerPrompt('You are the proposer')
            ->withCriticPrompt('You are the critic')
            ->withJudgePrompt('You are the judge');

        $this->assertEquals('You are the proposer', $config->proposerSystemPrompt);
        $this->assertEquals('You are the critic', $config->criticSystemPrompt);
        $this->assertEquals('You are the judge', $config->judgeSystemPrompt);
    }

    // ── DebateRound ──────────────────────────────────────────────

    public function test_round_creation(): void
    {
        $round = new DebateRound(
            roundNumber: 1,
            proposerArgument: 'Use approach A because it is simpler.',
            criticResponse: 'Approach A has a flaw: it does not scale.',
            proposerRebuttal: 'We can add caching to address scaling.',
            roundCost: 0.05,
            durationMs: 1200.0,
        );

        $this->assertEquals(1, $round->roundNumber);
        $this->assertEquals(0.05, $round->roundCost);
    }

    public function test_round_summary(): void
    {
        $round = new DebateRound(
            roundNumber: 2,
            proposerArgument: 'Short argument.',
            criticResponse: 'Short critique.',
        );

        $summary = $round->getSummary();
        $this->assertStringContainsString('Round 2', $summary);
        $this->assertStringContainsString('Short argument', $summary);
        $this->assertStringContainsString('Short critique', $summary);
    }

    public function test_round_to_array(): void
    {
        $round = new DebateRound(1, 'arg', 'crit', 'reb', 0.01, 100.0);
        $arr = $round->toArray();

        $this->assertEquals(1, $arr['round_number']);
        $this->assertEquals('arg', $arr['proposer_argument']);
        $this->assertEquals('crit', $arr['critic_response']);
        $this->assertEquals('reb', $arr['proposer_rebuttal']);
        $this->assertEquals(0.01, $arr['round_cost']);
        $this->assertEquals(100.0, $arr['duration_ms']);
    }

    public function test_round_without_rebuttal(): void
    {
        $round = new DebateRound(1, 'arg', 'crit');

        $this->assertNull($round->proposerRebuttal);
        $summary = $round->getSummary();
        $this->assertStringNotContainsString('Rebuttal', $summary);
    }

    // ── DebateResult ─────────────────────────────────────────────

    public function test_result_creation(): void
    {
        $rounds = [
            new DebateRound(1, 'arg1', 'crit1', null, 0.02, 500.0),
            new DebateRound(2, 'arg2', 'crit2', 'reb2', 0.03, 600.0),
        ];

        $result = new DebateResult(
            type: 'debate',
            topic: 'Which ORM to use?',
            rounds: $rounds,
            finalVerdict: 'Approach A is better.',
            recommendation: 'Use Eloquent.',
            totalCost: 0.05,
            totalDurationMs: 1100.0,
            agentContributions: ['proposer' => 0.02, 'critic' => 0.02, 'judge' => 0.01],
            totalTurns: 10,
        );

        $this->assertEquals('debate', $result->type);
        $this->assertEquals('Which ORM to use?', $result->topic);
        $this->assertCount(2, $result->getRounds());
        $this->assertEquals('Approach A is better.', $result->getVerdict());
    }

    public function test_result_cost_breakdown(): void
    {
        $result = new DebateResult(
            type: 'debate',
            topic: 'test',
            rounds: [new DebateRound(1, 'a', 'c')],
            finalVerdict: 'verdict',
            recommendation: 'rec',
            totalCost: 0.06,
            totalDurationMs: 500.0,
            agentContributions: ['p' => 0.03, 'c' => 0.03],
            totalTurns: 5,
        );

        $breakdown = $result->getCostBreakdown();
        $this->assertEquals(0.06, $breakdown['total']);
        $this->assertEquals(0.06, $breakdown['per_round']); // 1 round
        $this->assertArrayHasKey('p', $breakdown['agents']);
    }

    public function test_result_to_array(): void
    {
        $result = new DebateResult(
            type: 'red_team',
            topic: 'Security review',
            rounds: [],
            finalVerdict: 'No vulnerabilities found.',
            recommendation: 'Ship it.',
            totalCost: 0.01,
            totalDurationMs: 200.0,
            agentContributions: [],
            totalTurns: 2,
        );

        $arr = $result->toArray();
        $this->assertEquals('red_team', $arr['type']);
        $this->assertEquals('Security review', $arr['topic']);
        $this->assertEquals('No vulnerabilities found.', $arr['final_verdict']);
        $this->assertEquals('Ship it.', $arr['recommendation']);
        $this->assertEquals(2, $arr['total_turns']);
    }
}
