<?php

declare(strict_types=1);

/**
 * SuperAgent standalone polyfills for Laravel framework helpers.
 *
 * These functions are only defined when Laravel's helpers are NOT present.
 * In a Laravel application, illuminate/support defines these first via
 * Composer autoload, so our function_exists() checks skip them.
 */

if (! function_exists('config')) {
    /**
     * Get a configuration value.
     *
     * @param  string|array|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    function config(string|array|null $key = null, mixed $default = null): mixed
    {
        $repo = \SuperAgent\Config\ConfigRepository::getInstance();

        if ($key === null) {
            return $repo;
        }

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $repo->set($k, $v);
            }
            return null;
        }

        return $repo->get($key, $default);
    }
}

if (! function_exists('app')) {
    /**
     * Get the application instance or resolve a binding.
     *
     * @param  string|null  $abstract
     * @return mixed
     */
    function app(?string $abstract = null): mixed
    {
        $app = \SuperAgent\Foundation\Application::getInstance();

        if ($abstract === null) {
            return $app;
        }

        return $app->make($abstract);
    }
}

if (! function_exists('base_path')) {
    /**
     * Get the base path of the project.
     */
    function base_path(string $path = ''): string
    {
        return \SuperAgent\Foundation\Application::getInstance()->basePath($path);
    }
}

if (! function_exists('storage_path')) {
    /**
     * Get the storage path.
     * In standalone mode, defaults to ~/.superagent/storage/
     */
    function storage_path(string $path = ''): string
    {
        return \SuperAgent\Foundation\Application::getInstance()->storagePath($path);
    }
}

if (! function_exists('config_path')) {
    /**
     * Get the config path.
     */
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path ? '/' . $path : ''));
    }
}

if (! function_exists('env')) {
    /**
     * Get an environment variable value.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        // Parse boolean-like values
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (! function_exists('logger')) {
    /**
     * Get a logger instance.
     * In standalone mode, returns a NullLogger by default.
     *
     * @return \Psr\Log\LoggerInterface
     */
    function logger(): \Psr\Log\LoggerInterface
    {
        return new \Psr\Log\NullLogger();
    }
}
