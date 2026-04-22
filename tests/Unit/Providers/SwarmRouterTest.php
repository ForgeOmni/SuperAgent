<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\SwarmRouter;

class SwarmRouterTest extends TestCase
{
    protected function setUp(): void
    {
        ModelCatalog::clearOverrides();
    }

    protected function tearDown(): void
    {
        ModelCatalog::clearOverrides();
    }

    public function test_missing_prompt_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SwarmRouter::plan([]);
    }

    public function test_forced_strategy_is_honoured(): void
    {
        $plan = SwarmRouter::plan([
            'prompt' => 'do stuff',
            'strategy' => 'local_swarm',
        ]);
        $this->assertSame('local_swarm', $plan->strategy);
        $this->assertNull($plan->provider);
        $this->assertStringContainsString('forced', $plan->rationale);
    }

    public function test_kimi_provider_pin_selects_native_swarm(): void
    {
        $plan = SwarmRouter::plan([
            'prompt' => 'big task',
            'provider' => 'kimi',
        ]);
        $this->assertSame('native_swarm', $plan->strategy);
        $this->assertSame('kimi', $plan->provider);
    }

    public function test_minimax_provider_pin_selects_agent_teams(): void
    {
        $plan = SwarmRouter::plan([
            'prompt' => 'team task',
            'provider' => 'minimax',
        ]);
        $this->assertSame('agent_teams', $plan->strategy);
        $this->assertSame('minimax', $plan->provider);
    }

    public function test_large_max_sub_agents_upgrades_to_native_swarm(): void
    {
        $plan = SwarmRouter::plan([
            'prompt' => 'large task',
            'max_sub_agents' => 100,
        ]);
        $this->assertSame('native_swarm', $plan->strategy);
        $this->assertStringContainsString('100', $plan->rationale);
    }

    public function test_roles_declared_biases_to_agent_teams(): void
    {
        $plan = SwarmRouter::plan([
            'prompt' => 'role task',
            'roles' => [
                ['name' => 'researcher', 'description' => 'research'],
                ['name' => 'writer', 'description' => 'write'],
            ],
        ]);
        $this->assertSame('agent_teams', $plan->strategy);
        $this->assertStringContainsString('2 roles', $plan->rationale);
    }

    public function test_default_fallback_is_local_swarm(): void
    {
        $plan = SwarmRouter::plan(['prompt' => 'no hints']);
        $this->assertSame('local_swarm', $plan->strategy);
        $this->assertNull($plan->provider);
    }

    public function test_to_array_round_trip(): void
    {
        $plan = SwarmRouter::plan([
            'prompt' => 'test',
            'strategy' => 'local_swarm',
        ]);
        $arr = $plan->toArray();
        $this->assertSame('local_swarm', $arr['strategy']);
        $this->assertSame('test', $arr['prompt']);
        $this->assertArrayHasKey('rationale', $arr);
    }
}
