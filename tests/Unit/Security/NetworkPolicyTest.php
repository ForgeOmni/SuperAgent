<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SuperAgent\Security\NetworkPolicy;

class NetworkPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        NetworkPolicy::forceOffline(null);
        putenv('SUPERAGENT_OFFLINE');
    }

    public function test_online_by_default(): void
    {
        $this->assertFalse(NetworkPolicy::isOffline());
    }

    public function test_env_flag_turns_on_offline_mode(): void
    {
        putenv('SUPERAGENT_OFFLINE=1');
        $this->assertTrue(NetworkPolicy::isOffline());

        putenv('SUPERAGENT_OFFLINE=true');
        $this->assertTrue(NetworkPolicy::isOffline());

        putenv('SUPERAGENT_OFFLINE=0');
        $this->assertFalse(NetworkPolicy::isOffline());
    }

    public function test_force_overrides_env(): void
    {
        putenv('SUPERAGENT_OFFLINE=1');
        NetworkPolicy::forceOffline(false);
        $this->assertFalse(NetworkPolicy::isOffline());

        NetworkPolicy::forceOffline(true);
        $this->assertTrue(NetworkPolicy::isOffline());
    }

    public function test_check_allows_tool_without_network_attribute(): void
    {
        NetworkPolicy::forceOffline(true);
        $policy = NetworkPolicy::default();
        $decision = $policy->check(['cost', 'sensitive']);
        $this->assertTrue($decision->isAllow());
    }

    public function test_check_denies_network_tool_when_offline(): void
    {
        NetworkPolicy::forceOffline(true);
        $policy = NetworkPolicy::default();
        $decision = $policy->check(['network', 'cost']);
        $this->assertTrue($decision->isDeny());
        $this->assertStringContainsStringIgnoringCase('offline', $decision->reason);
    }

    public function test_check_allows_network_tool_when_online(): void
    {
        NetworkPolicy::forceOffline(false);
        $policy = NetworkPolicy::default();
        $this->assertTrue($policy->check(['network'])->isAllow());
    }
}
