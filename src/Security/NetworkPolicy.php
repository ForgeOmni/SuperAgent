<?php

declare(strict_types=1);

namespace SuperAgent\Security;

/**
 * Enforces an "offline mode" for tools declaring the `network` attribute.
 *
 * Activation sources, in precedence order:
 *   1. Runtime override via `NetworkPolicy::forceOffline(true)` — used by
 *      tests and by programmatic callers that want to bound an agent run.
 *   2. `SUPERAGENT_OFFLINE=1` env variable — the user-facing switch.
 *   3. Default: online (network tools pass through).
 *
 * The policy is deliberately attribute-driven rather than URL-based:
 *   - Phase-4 tools already declare `network` when they hit the public
 *     internet. Adding a URL allowlist on top would require every tool to
 *     surface its outbound URLs, which is noisy and leaky (MCP servers
 *     dial out opaquely). Hard-stop at the attribute level is simpler and
 *     captures the user's real intent ("don't talk to the network").
 *
 * This class is stateless aside from the forced-override flag; callers
 * construct `NetworkPolicy::default()` and pass it around, or use the
 * static helpers directly.
 */
final class NetworkPolicy
{
    private static ?bool $forced = null;

    public static function default(): self
    {
        return new self();
    }

    /**
     * Force the offline state for the remainder of the process (or until
     * `forceOffline(null)` clears it). Primarily a test hook — production
     * callers should prefer setting `SUPERAGENT_OFFLINE` in the environment
     * so child processes inherit the policy.
     */
    public static function forceOffline(?bool $value): void
    {
        self::$forced = $value;
    }

    public static function isOffline(): bool
    {
        if (self::$forced !== null) {
            return self::$forced;
        }

        $env = getenv('SUPERAGENT_OFFLINE');
        if ($env === false) {
            return false;
        }
        $lower = strtolower(trim((string) $env));
        return in_array($lower, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Gate a tool call by its declared `network` attribute.
     *
     * @param array<int, string> $toolAttributes
     */
    public function check(array $toolAttributes): SecurityDecision
    {
        $needsNetwork = in_array('network', $toolAttributes, true);
        if (! $needsNetwork) {
            return SecurityDecision::allow();
        }
        if (self::isOffline()) {
            return SecurityDecision::deny(
                'network access blocked (SUPERAGENT_OFFLINE is set)',
                ['attribute' => 'network'],
            );
        }
        return SecurityDecision::allow('network access permitted');
    }
}
