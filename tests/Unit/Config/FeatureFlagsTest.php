<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use SuperAgent\Config\FeatureFlags;

class FeatureFlagsTest extends TestCase
{
    private string $tmpHome;
    private ?string $origHome;
    private ?string $origProfile;
    private ?string $origDisable;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/superagent_flags_' . bin2hex(random_bytes(4));
        @mkdir($this->tmpHome . '/.superagent', 0755, true);

        $this->origHome = getenv('HOME') ?: null;
        $this->origProfile = getenv('USERPROFILE') ?: null;
        $this->origDisable = getenv('SUPERAGENT_DISABLE') ?: null;
        putenv('HOME=' . $this->tmpHome);
        putenv('USERPROFILE=' . $this->tmpHome);
        putenv('SUPERAGENT_DISABLE');

        FeatureFlags::reset();
    }

    protected function tearDown(): void
    {
        $path = FeatureFlags::configPath();
        if (is_file($path)) @unlink($path);
        @rmdir($this->tmpHome . '/.superagent');
        @rmdir($this->tmpHome);

        putenv('HOME' . ($this->origHome === null ? '' : '=' . $this->origHome));
        putenv('USERPROFILE' . ($this->origProfile === null ? '' : '=' . $this->origProfile));
        putenv('SUPERAGENT_DISABLE' . ($this->origDisable === null ? '' : '=' . $this->origDisable));

        FeatureFlags::reset();
    }

    public function test_unknown_flag_defaults_to_enabled(): void
    {
        $this->assertTrue(FeatureFlags::enabled('something.new'));
    }

    public function test_override_wins_over_file_and_env(): void
    {
        putenv('SUPERAGENT_DISABLE=foo');
        file_put_contents(FeatureFlags::configPath(), json_encode(['foo' => true]));
        FeatureFlags::reset();

        $this->assertFalse(FeatureFlags::enabled('foo'));  // env wins over file
        FeatureFlags::override('foo', true);
        $this->assertTrue(FeatureFlags::enabled('foo'));  // override wins over env
    }

    public function test_env_disable_accepts_comma_list(): void
    {
        putenv('SUPERAGENT_DISABLE=thinking, cost_limit ,  skills.user_dir');
        FeatureFlags::reset();

        $this->assertFalse(FeatureFlags::enabled('thinking'));
        $this->assertFalse(FeatureFlags::enabled('cost_limit'));
        $this->assertFalse(FeatureFlags::enabled('skills.user_dir'));
        $this->assertTrue(FeatureFlags::enabled('mcp.user_config'));
    }

    public function test_file_config_controls_flags(): void
    {
        file_put_contents(FeatureFlags::configPath(), json_encode([
            'thinking' => false,
            'agent_teams' => true,
        ]));
        FeatureFlags::reset();

        $this->assertFalse(FeatureFlags::enabled('thinking'));
        $this->assertTrue(FeatureFlags::enabled('agent_teams'));
    }

    public function test_override_null_clears(): void
    {
        FeatureFlags::override('x', false);
        $this->assertFalse(FeatureFlags::enabled('x'));
        FeatureFlags::override('x', null);
        $this->assertTrue(FeatureFlags::enabled('x'));  // back to default
    }

    public function test_snapshot_contains_all_effective_flags(): void
    {
        putenv('SUPERAGENT_DISABLE=one');
        file_put_contents(FeatureFlags::configPath(), json_encode(['two' => false]));
        FeatureFlags::override('three', true);
        FeatureFlags::reset();
        FeatureFlags::override('three', true);

        $snap = FeatureFlags::snapshot();
        $this->assertFalse($snap['one']);
        $this->assertFalse($snap['two']);
        $this->assertTrue($snap['three']);
    }
}
