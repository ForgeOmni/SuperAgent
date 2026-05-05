<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Swarm;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\AgentDepthExceededException;
use SuperAgent\Swarm\AgentDepthGuard;

class AgentDepthGuardTest extends TestCase
{
    protected function setUp(): void
    {
        AgentDepthGuard::setMax(null);
        unset($_SERVER['SUPERAGENT_AGENT_DEPTH']);
        unset($_ENV['SUPERAGENT_AGENT_DEPTH']);
        putenv('SUPERAGENT_AGENT_DEPTH');
        unset($_SERVER['SUPERAGENT_MAX_AGENT_DEPTH']);
        unset($_ENV['SUPERAGENT_MAX_AGENT_DEPTH']);
        putenv('SUPERAGENT_MAX_AGENT_DEPTH');
    }

    public function test_topmost_invocation_reports_depth_zero(): void
    {
        $this->assertSame(0, AgentDepthGuard::current());
    }

    public function test_default_max_is_five(): void
    {
        $this->assertSame(5, AgentDepthGuard::max());
    }

    public function test_env_override_wins_over_default(): void
    {
        $_SERVER['SUPERAGENT_MAX_AGENT_DEPTH'] = '8';
        $this->assertSame(8, AgentDepthGuard::max());
    }

    public function test_explicit_set_max_wins_over_env(): void
    {
        $_SERVER['SUPERAGENT_MAX_AGENT_DEPTH'] = '8';
        AgentDepthGuard::setMax(2);
        $this->assertSame(2, AgentDepthGuard::max());
    }

    public function test_check_passes_when_under_cap(): void
    {
        AgentDepthGuard::setMax(3);
        $_SERVER['SUPERAGENT_AGENT_DEPTH'] = '1';
        AgentDepthGuard::check(); // depth 1 → child depth 2, under 3
        $this->expectNotToPerformAssertions();
    }

    public function test_check_raises_when_at_cap(): void
    {
        AgentDepthGuard::setMax(3);
        $_SERVER['SUPERAGENT_AGENT_DEPTH'] = '3';
        $this->expectException(AgentDepthExceededException::class);
        AgentDepthGuard::check();
    }

    public function test_for_child_increments_current_by_one(): void
    {
        $_SERVER['SUPERAGENT_AGENT_DEPTH'] = '2';
        $env = AgentDepthGuard::forChild();
        $this->assertSame('3', $env['SUPERAGENT_AGENT_DEPTH']);
    }

    public function test_for_child_starts_at_one_from_topmost(): void
    {
        // depth=0 (topmost) → child depth=1. Correct off-by-one is
        // load-bearing — the check() at depth=1 with max=1 should fail.
        $env = AgentDepthGuard::forChild();
        $this->assertSame('1', $env['SUPERAGENT_AGENT_DEPTH']);
    }

    public function test_min_cap_is_one(): void
    {
        // Bogus 0 / negative cap is clamped — never let an
        // accidentally-zero config entirely disable the spawn path,
        // but never let it accept an absurd negative either.
        AgentDepthGuard::setMax(0);
        $this->assertSame(1, AgentDepthGuard::max());
        AgentDepthGuard::setMax(-7);
        $this->assertSame(1, AgentDepthGuard::max());
    }
}
