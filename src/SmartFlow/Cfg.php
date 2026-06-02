<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

use SuperAgent\Config\ConfigRepository;

/**
 * Dual-mode-safe config reader. The global `config()` helper resolves to
 * Laravel's when illuminate is present but throws a BindingResolutionException
 * if no application container is booted (e.g. a plain PHPUnit run or pure
 * standalone CLI). This reads SuperAgent's standalone {@see ConfigRepository}
 * first and only falls back to `config()` behind a try/catch, so SmartFlow never
 * crashes on configuration access in either mode.
 */
final class Cfg
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = ConfigRepository::getInstance()->get($key, null);
            if ($value !== null) {
                return $value;
            }
        } catch (\Throwable) {
            // fall through
        }

        if (function_exists('config')) {
            try {
                $value = config($key, null);
                if ($value !== null) {
                    return $value;
                }
            } catch (\Throwable) {
                // no booted container — ignore
            }
        }

        return $default;
    }
}
