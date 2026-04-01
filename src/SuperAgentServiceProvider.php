<?php

namespace SuperAgent;

use Illuminate\Support\ServiceProvider;

class SuperAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/superagent.php', 'superagent');

        $this->app->bind(Agent::class, function ($app) {
            return new Agent();
        });

        $this->app->alias(Agent::class, 'superagent');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/superagent.php' => config_path('superagent.php'),
            ], 'superagent-config');
        }
    }
}
