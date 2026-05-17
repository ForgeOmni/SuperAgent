<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

/**
 * Process-scoped SPI for the cross-mode router. Mirrors
 * `Squad\SquadDispatcherRegistry` exactly — single global slot,
 * `set / get / has / clear`. The point: SDK code paths that need to
 * recurse cross-mode (e.g. `PeerOrchestrator` resolving a
 * `SubTask.mode`) consult this registry first; a host that wants to
 * add CLI-layer modes registers its own `ModeRouter` once at boot.
 *
 * Loose coupling rules:
 *   - SDK itself NEVER calls `set()`. The slot is reserved for hosts.
 *   - SDK fallback: when no router is registered, callers build a
 *     local default with just the SDK's three orchestrators.
 *   - `clear()` exists for test isolation.
 */
final class ModeRouterRegistry
{
    private static ?ModeRouter $router = null;

    public static function set(?ModeRouter $router): void
    {
        self::$router = $router;
    }

    public static function get(): ?ModeRouter
    {
        return self::$router;
    }

    public static function has(): bool
    {
        return self::$router !== null;
    }

    public static function clear(): void
    {
        self::$router = null;
    }
}
