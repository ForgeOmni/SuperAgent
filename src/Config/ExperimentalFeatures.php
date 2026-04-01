<?php

declare(strict_types=1);

namespace SuperAgent\Config;

/**
 * Experimental feature flag helper.
 *
 * A feature is enabled when:
 *  - The master switch (superagent.experimental.enabled) is ON, OR
 *  - The individual feature's env var is explicitly set to true.
 *
 * When the master switch is ON, all implemented features are enabled
 * unless individually overridden to false.
 */
class ExperimentalFeatures
{
    /**
     * Check if an experimental feature is enabled.
     */
    public static function enabled(string $feature): bool
    {
        $masterEnabled = config('superagent.experimental.enabled', false);
        $featureValue = config("superagent.experimental.{$feature}");

        // If master switch is on, feature is enabled unless explicitly disabled
        if ($masterEnabled) {
            return $featureValue !== false;
        }

        // If master switch is off, only enabled if individually turned on
        return (bool) $featureValue;
    }

    /**
     * Get all feature flags and their current status.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $config = config('superagent.experimental', []);
        $flags = [];

        foreach ($config as $key => $value) {
            if ($key === 'enabled') {
                continue;
            }
            $flags[$key] = self::enabled($key);
        }

        return $flags;
    }
}
