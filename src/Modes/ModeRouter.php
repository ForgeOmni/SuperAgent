<?php

declare(strict_types=1);

namespace SuperAgent\Modes;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dispatches a task to the right `ModeOrchestrator` and threads the
 * `ModeContext` through. Used by:
 *
 *   - Top-level callers that want a single entry point regardless of
 *     mode (vs. instantiating each orchestrator manually).
 *   - `PeerOrchestrator` when a `SubTask` declared `mode: smart` /
 *     `mode: auto` etc. — the squad step needs to recurse without
 *     hard-coding the child mode's orchestrator class.
 *   - Hosts (SuperAICore) that subclass to add `cli:*` / `sdk:*`
 *     leaf provider tags alongside the mode names.
 *
 * Loose coupling with hosts: `ModeRouterRegistry::set()` lets a host
 * install its own subclass globally so any SDK code path that wants
 * to recurse picks the host's router up — exactly the pattern
 * `SquadDispatcherRegistry` uses.
 */
class ModeRouter
{
    /** @var array<string, ModeOrchestrator> */
    private array $orchestrators = [];

    public function __construct(
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Register (or replace) an orchestrator for a mode name.
     * Returns `$this` so chained construction reads naturally.
     */
    public function register(ModeOrchestrator $orchestrator): self
    {
        $this->orchestrators[$orchestrator->modeName()] = $orchestrator;
        return $this;
    }

    /**
     * Whether a mode is registered. Used by `dispatch()` to decide
     * between routing and an immediate `ModeNotRegisteredException`.
     */
    public function has(string $mode): bool
    {
        return isset($this->orchestrators[$mode]);
    }

    /**
     * Dispatch a task to the named mode. The caller is responsible
     * for descending the context if this dispatch is itself a child
     * call — the router intentionally does NOT auto-descend so a
     * top-level call doesn't see depth=1.
     *
     * @param array<string, mixed> $options
     */
    public function dispatch(string $mode, string $task, ModeContext $context, array $options = []): ModeResult
    {
        if (!isset($this->orchestrators[$mode])) {
            throw new ModeNotRegisteredException(
                "No orchestrator registered for mode '{$mode}'. "
                . "Registered: " . implode(', ', array_keys($this->orchestrators))
            );
        }
        $this->logger->info('ModeRouter: dispatching', [
            'mode'  => $mode,
            'depth' => $context->depth,
            'stack' => $context->modeStack,
        ]);
        return $this->orchestrators[$mode]->execute($task, $context, $options);
    }

    /**
     * Recursive dispatch — descends the context to a child mode AND
     * dispatches. The typical call site is inside a parent
     * orchestrator that's recursing into a sibling mode:
     *
     *   $result = $router->descend('smart', $childTask, $parentCtx);
     *
     * Returns the child's `ModeResult`. Throws on depth / cycle /
     * budget violations (the descend() check fires before dispatch).
     */
    public function descend(string $childMode, string $task, ModeContext $parent, array $options = []): ModeResult
    {
        $child = $parent->descend($childMode);
        return $this->dispatch($childMode, $task, $child, $options);
    }
}
