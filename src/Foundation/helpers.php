<?php

declare(strict_types=1);

/**
 * SuperAgent standalone polyfills for Laravel framework helpers.
 *
 * Composer's `files` autoload order across packages is non-deterministic.
 * Use class_exists() against Illuminate's bundled classes (which the
 * PSR-4 autoloader resolves on demand) so we never shadow Laravel's
 * versions when illuminate/support is installed.
 */

// Sentinel: if Illuminate's Container exists (always bundled with framework),
// we're inside a Laravel-style app — skip ALL our polyfills so Laravel's
// helpers.php can define them.
if (! class_exists(\Illuminate\Container\Container::class)) {

    if (! function_exists('config')) {
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
        function app(?string $abstract = null): mixed
        {
            $app = \SuperAgent\Foundation\Application::getInstance();
            return $abstract === null ? $app : $app->make($abstract);
        }
    }

    if (! function_exists('base_path')) {
        function base_path(string $path = ''): string
        {
            return \SuperAgent\Foundation\Application::getInstance()->basePath($path);
        }
    }

    if (! function_exists('storage_path')) {
        function storage_path(string $path = ''): string
        {
            return \SuperAgent\Foundation\Application::getInstance()->storagePath($path);
        }
    }

    if (! function_exists('config_path')) {
        function config_path(string $path = ''): string
        {
            return base_path('config' . ($path ? '/' . $path : ''));
        }
    }

    if (! function_exists('env')) {
        function env(string $key, mixed $default = null): mixed
        {
            $value = getenv($key);
            if ($value === false) return $default;
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
        function logger(): \Psr\Log\LoggerInterface
        {
            return new \Psr\Log\NullLogger();
        }
    }
}
