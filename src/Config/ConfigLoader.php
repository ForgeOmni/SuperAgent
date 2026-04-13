<?php

declare(strict_types=1);

namespace SuperAgent\Config;

/**
 * Multi-source configuration loader for standalone mode.
 *
 * Loads configuration from (in order of precedence, last wins):
 * 1. Package defaults   — config/superagent.php
 * 2. User global config — ~/.superagent/config.php
 * 3. Project config     — .superagent.php or superagent.config.php in project root
 * 4. Environment vars   — SUPERAGENT_* and provider-specific (ANTHROPIC_API_KEY, etc.)
 * 5. CLI overrides      — passed programmatically
 */
class ConfigLoader
{
    /**
     * Load configuration into a ConfigRepository.
     *
     * @param  string  $basePath   Project root directory
     * @param  array   $overrides  CLI or programmatic overrides
     */
    public static function load(string $basePath, array $overrides = []): ConfigRepository
    {
        $config = [];

        // 1. Package defaults
        $packageConfig = dirname(__DIR__, 2) . '/config/superagent.php';
        if (file_exists($packageConfig)) {
            $loaded = self::loadPhpFile($packageConfig);
            if (is_array($loaded)) {
                $config['superagent'] = $loaded;
            }
        }

        // 2. User global config (~/.superagent/config.php)
        $globalConfig = self::homeDir() . '/.superagent/config.php';
        if (file_exists($globalConfig)) {
            $loaded = self::loadPhpFile($globalConfig);
            if (is_array($loaded)) {
                $config['superagent'] = self::deepMerge($config['superagent'] ?? [], $loaded);
            }
        }

        // 3. Project config
        foreach (['.superagent.php', 'superagent.config.php'] as $filename) {
            $projectConfig = $basePath . '/' . $filename;
            if (file_exists($projectConfig)) {
                $loaded = self::loadPhpFile($projectConfig);
                if (is_array($loaded)) {
                    $config['superagent'] = self::deepMerge($config['superagent'] ?? [], $loaded);
                }
                break;
            }
        }

        // 4. Environment variable overlays
        $config = self::applyEnvOverrides($config);

        // 5. Programmatic overrides
        if (! empty($overrides)) {
            $config['superagent'] = self::deepMerge($config['superagent'] ?? [], $overrides);
        }

        $repo = new ConfigRepository($config);
        ConfigRepository::setInstance($repo);

        return $repo;
    }

    /**
     * Apply environment variable overrides to config.
     */
    private static function applyEnvOverrides(array $config): array
    {
        $envMap = [
            'ANTHROPIC_API_KEY'    => 'superagent.providers.anthropic.api_key',
            'OPENAI_API_KEY'       => 'superagent.providers.openai.api_key',
            'OPENROUTER_API_KEY'   => 'superagent.providers.openrouter.api_key',
            'OLLAMA_BASE_URL'      => 'superagent.providers.ollama.base_url',
            'SUPERAGENT_PROVIDER'  => 'superagent.default_provider',
            'SUPERAGENT_MODEL'     => 'superagent.model',
            'SUPERAGENT_MAX_TURNS' => 'superagent.max_turns',
        ];

        foreach ($envMap as $envVar => $configKey) {
            $value = getenv($envVar);
            if ($value !== false && $value !== '') {
                $segments = explode('.', $configKey);
                $current = &$config;
                foreach ($segments as $i => $segment) {
                    if ($i === count($segments) - 1) {
                        $current[$segment] = $value;
                    } else {
                        if (! isset($current[$segment])) {
                            $current[$segment] = [];
                        }
                        $current = &$current[$segment];
                    }
                }
                unset($current);
            }
        }

        return $config;
    }

    /**
     * Safely load a PHP config file that returns an array.
     */
    private static function loadPhpFile(string $path): mixed
    {
        try {
            return require $path;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Deep merge two arrays.
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Get the user's home directory.
     */
    public static function homeDir(): string
    {
        // Windows
        if (isset($_SERVER['USERPROFILE'])) {
            return $_SERVER['USERPROFILE'];
        }

        // Unix
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME');
        if ($home !== false && $home !== '') {
            return $home;
        }

        // Fallback
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $info = posix_getpwuid(posix_getuid());
            if (isset($info['dir'])) {
                return $info['dir'];
            }
        }

        return sys_get_temp_dir();
    }
}
