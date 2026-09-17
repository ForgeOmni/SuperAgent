<?php

namespace SuperAgent\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use SuperAgent\Agent;
use SuperAgent\Facades\SuperAgent;
use SuperAgent\SuperAgentServiceProvider;

/**
 * Boots the package inside a real Laravel application.
 *
 * The rest of the suite exercises the SDK framework-agnostically, which is
 * why a framework major could change under us unnoticed: nothing asserted
 * that `SuperAgentServiceProvider` still registers, merges its config,
 * resolves its bindings and registers its Artisan commands. This test is the
 * one the CI Laravel matrix (10 / 11 / 12 / 13) runs per framework major.
 *
 * Skips instead of failing when Testbench is not installed, so the plain
 * PHPUnit matrix — which installs no framework at all — stays green.
 */
class ServiceProviderBootTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! class_exists(TestCase::class)) {
            self::markTestSkipped('orchestra/testbench is not installed.');
        }

        parent::setUpBeforeClass();
    }

    protected function getPackageProviders($app): array
    {
        return [SuperAgentServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['SuperAgent' => SuperAgent::class];
    }

    /**
     * The container binding builds a provider eagerly, so resolving `Agent`
     * out of the container needs a credential present. A dummy key is enough
     * — nothing in this suite performs a request.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('superagent.providers.anthropic.api_key', 'test-key-not-used');
    }

    public function test_provider_is_registered(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(SuperAgentServiceProvider::class),
            'SuperAgentServiceProvider did not load.'
        );
    }

    public function test_config_is_merged(): void
    {
        // A key that only the package's own config file can supply.
        $this->assertIsArray(config('superagent.providers'));
        $this->assertNotEmpty(config('superagent.default_provider'));
    }

    public function test_agent_binding_resolves(): void
    {
        $this->assertInstanceOf(Agent::class, $this->app->make(Agent::class));
        $this->assertInstanceOf(Agent::class, $this->app->make('superagent'));
    }

    public function test_artisan_commands_are_registered(): void
    {
        $commands = array_keys($this->app[\Illuminate\Contracts\Console\Kernel::class]->all());

        foreach (['superagent:feedback', 'superagent:distill', 'superagent:checkpoint', 'superagent:wake-up'] as $name) {
            $this->assertContains($name, $commands, "Artisan command {$name} was not registered.");
        }
    }
}
