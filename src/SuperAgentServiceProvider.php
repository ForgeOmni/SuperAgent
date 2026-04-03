<?php

namespace SuperAgent;

use Illuminate\Support\ServiceProvider;
use SuperAgent\Guardrails\GuardrailsConfig;
use SuperAgent\Guardrails\GuardrailsEngine;

class SuperAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/superagent.php', 'superagent');

        $this->app->bind(Agent::class, function ($app) {
            return new Agent();
        });

        $this->app->alias(Agent::class, 'superagent');

        // Register GuardrailsEngine singleton when enabled
        $this->app->singleton(GuardrailsEngine::class, function ($app) {
            $config = $app['config']->get('superagent.guardrails', []);

            if (empty($config['enabled'])) {
                return null;
            }

            $files = $config['files'] ?? [];
            if (empty($files)) {
                return null;
            }

            $guardrailsConfig = GuardrailsConfig::fromYamlFiles($files);
            $errors = $guardrailsConfig->validate();

            if (!empty($errors)) {
                logger()->warning('Guardrails config validation errors', ['errors' => $errors]);
            }

            return new GuardrailsEngine($guardrailsConfig);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/superagent.php' => config_path('superagent.php'),
            ], 'superagent-config');
        }

        // Register Bridge routes when bridge_mode is enabled
        if (\SuperAgent\Config\ExperimentalFeatures::enabled('bridge_mode')) {
            $this->app->register(\SuperAgent\Bridge\BridgeServiceProvider::class);
        }
    }
}
