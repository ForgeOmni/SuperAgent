<?php

namespace SuperAgent\Telemetry;

use Illuminate\Support\Collection;
use Carbon\Carbon;

class EventDispatcher
{
    private static ?self $instance = null;
    private Collection $listeners;
    private Collection $eventHistory;
    private bool $enabled;
    private int $maxHistorySize = 1000;

    public function __construct()
    {
        $this->listeners = collect();
        $this->eventHistory = collect();
        $this->enabled = config('superagent.telemetry.enabled', false)
            && config('superagent.telemetry.events.enabled', false);
    }

    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register an event listener.
     */
    public function listen(string $event, callable $callback, int $priority = 0): string
    {
        $listenerId = uniqid('listener_');

        if (!$this->listeners->has($event)) {
            $this->listeners->put($event, collect());
        }

        $this->listeners->get($event)->push([
            'id' => $listenerId,
            'callback' => $callback,
            'priority' => $priority,
        ]);

        // Sort by priority (higher priority first)
        $sorted = $this->listeners->get($event)->sortByDesc('priority');
        $this->listeners->put($event, $sorted->values());

        return $listenerId;
    }

    /**
     * Remove an event listener.
     */
    public function removeListener(string $listenerId): bool
    {
        foreach ($this->listeners as $event => $listeners) {
            $filtered = $listeners->reject(fn($listener) => $listener['id'] === $listenerId);
            if ($filtered->count() < $listeners->count()) {
                $this->listeners->put($event, $filtered);
                return true;
            }
        }
        return false;
    }

    /**
     * Dispatch an event.
     */
    public function dispatch(string $event, array $data = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $eventData = [
            'event' => $event,
            'data' => $data,
            'timestamp' => Carbon::now()->toIso8601String(),
            'dispatched_at' => microtime(true),
        ];

        // Add to history
        $this->addToHistory($eventData);

        // Notify listeners
        if ($this->listeners->has($event)) {
            foreach ($this->listeners->get($event) as $listener) {
                try {
                    call_user_func($listener['callback'], $data);
                } catch (\Exception $e) {
                    logger()->error("Event listener error for {$event}: " . $e->getMessage());
                }
            }
        }

        // Also dispatch wildcard listeners
        if ($this->listeners->has('*')) {
            foreach ($this->listeners->get('*') as $listener) {
                try {
                    call_user_func($listener['callback'], $event, $data);
                } catch (\Exception $e) {
                    logger()->error("Wildcard listener error: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Dispatch standard telemetry events.
     */
    public function dispatchSessionStart(string $sessionId, array $metadata = []): void
    {
        $this->dispatch('session.started', array_merge([
            'session_id' => $sessionId,
        ], $metadata));
    }

    public function dispatchSessionEnd(string $sessionId, array $summary = []): void
    {
        $this->dispatch('session.ended', array_merge([
            'session_id' => $sessionId,
        ], $summary));
    }

    public function dispatchLLMRequest(string $model, array $request, array $metadata = []): void
    {
        $this->dispatch('llm.request', array_merge([
            'model' => $model,
            'message_count' => count($request['messages'] ?? []),
        ], $metadata));
    }

    public function dispatchLLMResponse(string $model, array $response, float $duration): void
    {
        $this->dispatch('llm.response', [
            'model' => $model,
            'duration_ms' => $duration,
            'usage' => $response['usage'] ?? null,
            'finish_reason' => $response['finish_reason'] ?? null,
        ]);
    }

    public function dispatchToolStart(string $toolName, array $input): void
    {
        $this->dispatch('tool.started', [
            'tool' => $toolName,
            'input_size' => strlen(json_encode($input)),
        ]);
    }

    public function dispatchToolComplete(string $toolName, $result, float $duration): void
    {
        $this->dispatch('tool.completed', [
            'tool' => $toolName,
            'duration_ms' => $duration,
            'success' => true,
            'result_size' => strlen(json_encode($result)),
        ]);
    }

    public function dispatchToolError(string $toolName, string $error, float $duration): void
    {
        $this->dispatch('tool.error', [
            'tool' => $toolName,
            'error' => $error,
            'duration_ms' => $duration,
            'success' => false,
        ]);
    }

    public function dispatchError(string $message, \Throwable $exception = null): void
    {
        $data = ['message' => $message];
        
        if ($exception) {
            $data['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ];
        }

        $this->dispatch('error', $data);
    }

    public function dispatchMetric(string $name, float $value, array $labels = []): void
    {
        $this->dispatch('metric', [
            'name' => $name,
            'value' => $value,
            'labels' => $labels,
        ]);
    }

    /**
     * Add event to history.
     */
    private function addToHistory(array $eventData): void
    {
        $this->eventHistory->push($eventData);

        // Limit history size
        if ($this->eventHistory->count() > $this->maxHistorySize) {
            $this->eventHistory->shift();
        }
    }

    /**
     * Get event history.
     */
    public function getHistory(string $event = null, int $limit = 100): Collection
    {
        $history = $this->eventHistory;

        if ($event) {
            $history = $history->filter(fn($item) => $item['event'] === $event);
        }

        return $history->take(-$limit)->values();
    }

    /**
     * Clear event history.
     */
    public function clearHistory(): void
    {
        $this->eventHistory = collect();
    }

    /**
     * Get listener count for an event.
     */
    public function getListenerCount(string $event): int
    {
        return $this->listeners->get($event, collect())->count();
    }

    /**
     * Check if events are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all listeners (for testing).
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->listeners = collect();
            self::$instance->eventHistory = collect();
        }
    }
}