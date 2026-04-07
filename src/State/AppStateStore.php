<?php

declare(strict_types=1);

namespace SuperAgent\State;

/**
 * Observable store for AppState.
 *
 * Follows a minimal pub/sub pattern:
 *   - set() merges updates into the current state and notifies all subscribers.
 *   - subscribe() returns an unsubscribe callable.
 */
class AppStateStore
{
    private AppState $state;

    /** @var array<int, callable(AppState): void> */
    private array $listeners = [];

    private int $nextId = 0;

    public function __construct(?AppState $initial = null)
    {
        $this->state = $initial ?? new AppState();
    }

    /**
     * Get the current state snapshot.
     */
    public function get(): AppState
    {
        return $this->state;
    }

    /**
     * Apply partial updates and notify all listeners.
     *
     * @param array $updates  Keys matching AppState constructor params.
     */
    public function set(array $updates): void
    {
        $this->state = $this->state->with($updates);

        foreach ($this->listeners as $listener) {
            ($listener)($this->state);
        }
    }

    /**
     * Subscribe to state changes.
     *
     * @param callable(AppState): void $listener
     * @return callable  Unsubscribe function — call it to remove the listener.
     */
    public function subscribe(callable $listener): callable
    {
        $id = $this->nextId++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /**
     * Return the number of active listeners.
     */
    public function getListenerCount(): int
    {
        return count($this->listeners);
    }
}
