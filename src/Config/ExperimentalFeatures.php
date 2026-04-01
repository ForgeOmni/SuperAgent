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
 *
 * Falls back to env vars when running outside a Laravel application
 * (e.g. in unit tests without a booted app container).
 */
class ExperimentalFeatures
{
    /**
     * Check if an experimental feature is enabled.
     */
    public static function enabled(string $feature): bool
    {
        // When config() is unavailable (unit tests), fall back to env vars
        if (!function_exists('config') || !self::configAvailable()) {
            return self::fromEnv($feature);
        }

        $masterEnabled = config('superagent.experimental.enabled', true);
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
        if (!function_exists('config') || !self::configAvailable()) {
            return [];
        }

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

    /**
     * Check if the config repository is available (Laravel app booted).
     */
    private static function configAvailable(): bool
    {
        try {
            config('superagent.experimental.enabled');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fall back to environment variables when Laravel config is unavailable.
     * Defaults to true (all features enabled) to match config defaults.
     */
    private static function fromEnv(string $feature): bool
    {
        $envMap = [
            'ultrathink' => 'SUPERAGENT_EXP_ULTRATHINK',
            'token_budget' => 'SUPERAGENT_EXP_TOKEN_BUDGET',
            'prompt_cache_break_detection' => 'SUPERAGENT_EXP_PROMPT_CACHE',
            'builtin_agents' => 'SUPERAGENT_EXP_BUILTIN_AGENTS',
            'verification_agent' => 'SUPERAGENT_EXP_VERIFICATION_AGENT',
            'plan_interview' => 'SUPERAGENT_EXP_PLAN_INTERVIEW',
            'agent_triggers' => 'SUPERAGENT_EXP_AGENT_TRIGGERS',
            'agent_triggers_remote' => 'SUPERAGENT_EXP_AGENT_TRIGGERS_REMOTE',
            'extract_memories' => 'SUPERAGENT_EXP_EXTRACT_MEMORIES',
            'compaction_reminders' => 'SUPERAGENT_EXP_COMPACTION_REMINDERS',
            'cached_microcompact' => 'SUPERAGENT_EXP_CACHED_MICROCOMPACT',
            'team_memory' => 'SUPERAGENT_EXP_TEAM_MEMORY',
            'bash_classifier' => 'SUPERAGENT_EXP_BASH_CLASSIFIER',
            'voice_mode' => 'SUPERAGENT_EXP_VOICE_MODE',
            'bridge_mode' => 'SUPERAGENT_EXP_BRIDGE_MODE',
        ];

        $envKey = $envMap[$feature] ?? null;
        if ($envKey === null) {
            return true; // Unknown features default to enabled
        }

        $value = $_ENV[$envKey] ?? getenv($envKey);
        if ($value === false || $value === '') {
            return true; // Default: enabled
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
