<?php

declare(strict_types=1);

/**
 * Standalone bootstrap for SuperAgent CLI.
 *
 * This script initializes the SuperAgent application when running
 * outside of a Laravel application. It:
 *
 * 1. Locates and loads the Composer autoloader
 * 2. Creates the Application container
 * 3. Loads configuration from multiple sources
 * 4. Registers core services
 *
 * When running inside Laravel, this file is never called — Laravel's
 * own bootstrap handles everything, and SuperAgentServiceProvider
 * registers the services.
 */

namespace SuperAgent\Foundation;

use SuperAgent\Config\ConfigLoader;
use SuperAgent\Config\ConfigRepository;

/**
 * Bootstrap the standalone SuperAgent application.
 *
 * @param  string  $basePath   Project working directory (default: getcwd())
 * @param  array   $overrides  Config overrides from CLI flags
 * @return Application
 */
function bootstrap(string $basePath = '', array $overrides = []): Application
{
    $basePath = $basePath ?: getcwd();

    // 1. Create Application instance
    $app = new Application($basePath);
    Application::setInstance($app);

    // 2. Load configuration
    ConfigLoader::load($basePath, $overrides);

    // 3. Ensure storage directory exists
    $storagePath = $app->storagePath();
    if (! is_dir($storagePath)) {
        @mkdir($storagePath, 0755, true);
    }

    // 4. Register core services
    $app->registerCoreServices();

    // 5. Register model aliases from config
    $modelAliases = ConfigRepository::getInstance()->get('superagent.model_aliases', []);
    if (! empty($modelAliases) && class_exists(\SuperAgent\Providers\ModelResolver::class)) {
        \SuperAgent\Providers\ModelResolver::registerAliases($modelAliases);
    }

    return $app;
}
