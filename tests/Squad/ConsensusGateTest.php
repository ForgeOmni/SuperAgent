<?php

namespace SuperAgent\Tests\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\ConsensusGate;

class ConsensusGateTest extends TestCase
{
    private function gate(int $n, int $m): ConsensusGate
    {
        $voters = array_fill(0, $m, ['role' => 'voter']);
        return new ConsensusGate('test', $n, $m, $voters, 'Respond VERDICT: APPROVE|REJECT|ABSTAIN');
    }

    public function test_passes_when_n_of_m_approve(): void
    {
        $g = $this->gate(3, 4);
        $out = $g->tally([
            'VERDICT: APPROVE — ship it',
            'VERDICT: APPROVE — looks good',
            'VERDICT: APPROVE — fine',
            'VERDICT: REJECT — too risky',
        ]);
        $this->assertTrue($out['passed']);
        $this->assertSame(ConsensusGate::VERDICT_APPROVE, $out['verdict']);
        $this->assertSame(3, $out['counts']['APPROVE']);
        $this->assertSame(1, $out['counts']['REJECT']);
    }

    public function test_fails_when_short_of_n(): void
    {
        $g = $this->gate(3, 4);
        $out = $g->tally([
            'VERDICT: APPROVE',
            'VERDICT: APPROVE',
            'VERDICT: REJECT — security concern',
            'VERDICT: REJECT — flaky test',
        ]);
        $this->assertFalse($out['passed']);
        $this->assertSame(ConsensusGate::VERDICT_REJECT, $out['verdict']);
    }

    public function test_abstain_decides_when_balanced(): void
    {
        $g = $this->gate(2, 3);
        $out = $g->tally([
            'VERDICT: APPROVE',
            'VERDICT: ABSTAIN — need more info',
            'VERDICT: ABSTAIN',
        ]);
        $this->assertFalse($out['passed']);
        $this->assertSame(ConsensusGate::VERDICT_ABSTAIN, $out['verdict']);
    }

    public function test_lenient_parsing_for_noncompliant_responses(): void
    {
        $this->assertSame('APPROVE', ConsensusGate::parseVerdict('Approved — looks fine to me.'));
        $this->assertSame('REJECT', ConsensusGate::parseVerdict('I have rejected this because…'));
        $this->assertSame('ABSTAIN', ConsensusGate::parseVerdict('Hmm, I am not sure.'));
    }

    public function test_constructor_validates_n_m(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConsensusGate('bad', 5, 3, array_fill(0, 3, ['role' => 'x']));
    }

    public function test_constructor_validates_voter_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConsensusGate('bad', 2, 4, [['role' => 'x'], ['role' => 'y']]);
    }
}
