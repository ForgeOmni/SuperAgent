<?php

declare(strict_types=1);

namespace SuperAgent\Config;

use SuperAgent\Tools\ToolPolicy;

/**
 * The posture an Agent is built with.
 *
 * `workstation` is what this SDK has always done: a developer's machine is the
 * workspace, so the default tool set — shell, file edits, git, HTTP — loads
 * unless the caller says otherwise.
 *
 * `embedded` is the posture a host wants when the SDK runs inside its own
 * product, serving people who are not its developers: nothing loads that the
 * host did not hand over, and anything that can reach the machine or the
 * network is refused by policy even if it is handed over by mistake.
 *
 * A profile supplies **defaults only**. Anything the caller passes explicitly
 * wins, in both directions — an embedded agent can be given a file tool on
 * purpose by naming it, and a workstation agent can be locked down by setting
 * a policy. The default profile is `workstation`, so an existing caller that
 * sets nothing behaves exactly as before.
 *
 * @since 1.2.0
 */
final class Profile
{
    public const WORKSTATION = 'workstation';
    public const EMBEDDED = 'embedded';

    /** Resolution order: explicit config → superagent.profile → env → workstation. */
    public static function resolve(array $config): string
    {
        $name = $config['profile']
            ?? self::config('superagent.profile')
            ?? getenv('SUPERAGENT_PROFILE')
            ?: self::WORKSTATION;

        $name = strtolower(trim((string) $name));

        return $name === self::EMBEDDED ? self::EMBEDDED : self::WORKSTATION;
    }

    /**
     * Defaults the profile contributes, as a config array in the same shape
     * the Agent constructor accepts. Nested keys are merged one level deep so
     * a caller that sets `tool_loader.lazy_load` does not lose `auto_load`.
     */
    public static function defaults(string $profile): array
    {
        if ($profile !== self::EMBEDDED) {
            return [];
        }

        return [
            // Nothing loads that the host did not hand over.
            'tool_loader' => ['auto_load' => false],

            // Whatever is handed over still has to pass the policy.
            'tool_policy' => ['deny_categories' => ToolPolicy::HOST_CATEGORIES],

            // Behaviour an embedded host has not asked for and cannot see:
            // experimental paths, plugin discovery, Claude Code skill/agent
            // directories on the host's disk, and local persistence.
            'experimental' => ['enabled' => false],
            'plugins' => ['enabled' => false],
            'skills' => ['load_claude_code' => false],
            'agents' => ['load_claude_code' => false],
            'persistence' => ['enabled' => false],
        ];
    }

    /**
     * Applies the profile's defaults underneath $config.
     *
     * Top-level keys the caller set are kept as they are; for the nested
     * arrays the profile touches, the caller's keys win individually.
     */
    public static function apply(array $config): array
    {
        $profile = self::resolve($config);
        $config['profile'] = $profile;

        foreach (self::defaults($profile) as $key => $value) {
            if (! array_key_exists($key, $config)) {
                $config[$key] = $value;
                continue;
            }

            if (is_array($value) && is_array($config[$key])) {
                $config[$key] += $value;   // caller's keys win
            }
        }

        return $config;
    }

    private static function config(string $key): mixed
    {
        if (! function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (\Throwable) {
            return null;
        }
    }
}
