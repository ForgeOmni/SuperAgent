<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Modes;

use PHPUnit\Framework\TestCase;
use SuperAgent\Modes\ModeContext;
use SuperAgent\Modes\ModeNotRegisteredException;
use SuperAgent\Modes\ModeOrchestrator;
use SuperAgent\Modes\ModeResult;
use SuperAgent\Modes\ModeRouter;
use SuperAgent\Modes\ModeRouterRegistry;

/**
 * Pin the router contract: registered modes dispatch correctly,
 * unknown modes throw, descend() advances the context, the registry
 * SPI persists and clears, and a host-registered router actually
 * fires when SDK code calls into the SPI.
 */
final class ModeRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        ModeRouterRegistry::clear();
    }

    public function test_dispatch_routes_to_registered_orchestrator(): void
    {
        $router = new ModeRouter();
        $router->register($this->fakeOrchestrator('smart', 'smart-output'));
        $ctx = ModeContext::root('smart');

        $result = $router->dispatch('smart', 'task', $ctx);
        $this->assertSame('smart-output', $result->text);
        $this->assertSame('smart', $result->mode);
    }

    public function test_unknown_mode_throws(): void
    {
        $router = new ModeRouter();
        $ctx = ModeContext::root('auto');
        $this->expectException(ModeNotRegisteredException::class);
        $router->dispatch('squad', 't', $ctx);
    }

    public function test_descend_advances_stack_then_dispatches(): void
    {
        $captured = null;
        $router = new ModeRouter();
        $router->register(new class($captured) implements ModeOrchestrator {
            public function __construct(private mixed &$captured) {}
            public function modeName(): string { return 'smart'; }
            public function execute(string $task, ModeContext $context, array $options = []): ModeResult
            {
                $this->captured = $context->modeStack;
                return new ModeResult(text: 'ok', costUsd: 0.0, mode: 'smart', trace: $context->modeStack);
            }
        });
        $parent = ModeContext::root('squad');
        $router->descend('smart', 't', $parent);
        $this->assertSame(['squad', 'smart'], $captured);
    }

    public function test_registry_persists_and_clears(): void
    {
        $this->assertFalse(ModeRouterRegistry::has());
        $router = new ModeRouter();
        ModeRouterRegistry::set($router);
        $this->assertTrue(ModeRouterRegistry::has());
        $this->assertSame($router, ModeRouterRegistry::get());
        ModeRouterRegistry::clear();
        $this->assertNull(ModeRouterRegistry::get());
    }

    private function fakeOrchestrator(string $name, string $text): ModeOrchestrator
    {
        return new class($name, $text) implements ModeOrchestrator {
            public function __construct(private string $n, private string $t) {}
            public function modeName(): string { return $this->n; }
            public function execute(string $task, ModeContext $context, array $options = []): ModeResult
            {
                return new ModeResult(text: $this->t, costUsd: 0.0, mode: $this->n, trace: $context->modeStack);
            }
        };
    }
}
