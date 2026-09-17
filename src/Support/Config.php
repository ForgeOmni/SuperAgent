<?php

declare(strict_types=1);

namespace SuperAgent\Support;

/**
 * Reads configuration without assuming a framework is booted.
 *
 * The `config()` polyfill in `Foundation/helpers.php` steps aside whenever
 * Illuminate's Container class is present, so in any process that merely has
 * illuminate/support on the autoloader — a worker, a CLI, a test suite — the
 * global helper resolves Laravel's version and throws if no application was
 * booted. Classes that read config in their constructor therefore fatal in
 * exactly the environments this SDK claims to support standalone.
 *
 * @since 1.2.0
 */
final class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            $value = config($key, $default);
        } catch (\Throwable) {
            return $default;
        }

        return $value ?? $default;
    }
}
