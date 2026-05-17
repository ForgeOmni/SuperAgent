<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Squad;

use PHPUnit\Framework\TestCase;
use SuperAgent\Squad\SquadDispatcherRegistry;

/**
 * The registry is a process-scoped extension point — its full surface
 * is set/get/has/clear plus the precedence semantics
 * AutoModeAgent::runSquad relies on (per-call config wins, then
 * registry, then SDK inline default).
 *
 * Tests pin: the slot is null by default; set() persists; replace
 * semantics; clear() rolls back; has() tracks set/clear correctly.
 */
final class SquadDispatcherRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Static state — always reset between tests so order doesn't
        // matter and a forgotten `set()` doesn't leak across the suite.
        SquadDispatcherRegistry::clear();
    }

    public function test_unset_by_default(): void
    {
        SquadDispatcherRegistry::clear();
        $this->assertNull(SquadDispatcherRegistry::get());
        $this->assertFalse(SquadDispatcherRegistry::has());
    }

    public function test_set_persists_dispatcher(): void
    {
        $dispatcher = fn () => ['output' => 'sentinel', 'cost_usd' => 0.0];
        SquadDispatcherRegistry::set($dispatcher);
        $this->assertTrue(SquadDispatcherRegistry::has());
        $this->assertSame($dispatcher, SquadDispatcherRegistry::get());
    }

    public function test_set_replaces_previous(): void
    {
        $first  = fn () => ['output' => 'one', 'cost_usd' => 0.0];
        $second = fn () => ['output' => 'two', 'cost_usd' => 0.0];
        SquadDispatcherRegistry::set($first);
        SquadDispatcherRegistry::set($second);
        $this->assertSame($second, SquadDispatcherRegistry::get());
    }

    public function test_set_null_un_registers(): void
    {
        SquadDispatcherRegistry::set(fn () => null);
        $this->assertTrue(SquadDispatcherRegistry::has());
        SquadDispatcherRegistry::set(null);
        $this->assertFalse(SquadDispatcherRegistry::has());
        $this->assertNull(SquadDispatcherRegistry::get());
    }

    public function test_clear_drops_dispatcher(): void
    {
        SquadDispatcherRegistry::set(fn () => null);
        SquadDispatcherRegistry::clear();
        $this->assertNull(SquadDispatcherRegistry::get());
        $this->assertFalse(SquadDispatcherRegistry::has());
    }
}
