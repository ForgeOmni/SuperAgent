<?php

namespace SuperAgent\Tests;

use PHPUnit\Framework\TestCase;
use SuperAgent\Agent;

/**
 * Focused unit test for the steer/followUp enqueue+drain API on Agent.
 *
 * We don't drive a full engine here — the wiring from QueryEngine into
 * Agent::drainSteer() is verified separately (see QueryEngine docs). This
 * test pins the API contract: steer() pushes, drainSteer() pops in FIFO
 * and empties; same for followUp().
 */
class AgentSteerTest extends TestCase
{
    private function makeAgent(): Agent
    {
        // Construct without provider config — Agent allows that for
        // configuration-only flows. (If construction fails, this test
        // surfaces an SDK regression worth investigating.)
        $r = new \ReflectionClass(Agent::class);
        $agent = $r->newInstanceWithoutConstructor();
        $r->getProperty('options')->setValue($agent, []);
        return $agent;
    }

    public function test_steer_enqueue_and_drain_is_fifo(): void
    {
        $a = $this->makeAgent();
        $a->steer('first');
        $a->steer('second');

        $drained = $a->drainSteer();
        $this->assertCount(2, $drained);
        $this->assertSame('first',  $drained[0]['message']);
        $this->assertSame('second', $drained[1]['message']);

        // Queue empties after drain
        $this->assertSame([], $a->drainSteer());
    }

    public function test_followup_independent_of_steer(): void
    {
        $a = $this->makeAgent();
        $a->steer('steer-1');
        $a->followUp('follow-1');

        $this->assertCount(1, $a->drainSteer());
        $this->assertCount(1, $a->drainFollowUp());
        // Each drain empties only its own queue
        $this->assertSame([], $a->drainSteer());
        $this->assertSame([], $a->drainFollowUp());
    }

    public function test_drain_timestamps_present(): void
    {
        $a = $this->makeAgent();
        $a->steer('x');
        $drained = $a->drainSteer();
        $this->assertArrayHasKey('at', $drained[0]);
        $this->assertGreaterThan(0.0, $drained[0]['at']);
    }
}
