<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

use SuperAgent\Swarm\Backends\BackendInterface;
use SuperAgent\Swarm\Backends\InProcessBackend;
use SuperAgent\Swarm\Backends\ProcessBackend;
use SuperAgent\Swarm\Backends\TmuxBackend;
use SuperAgent\Swarm\Backends\ITermBackend;

/**
 * Singleton registry for agent execution backends.
 *
 * Provides auto-detection of the best available backend and
 * lazy-instantiation of backend instances by type.
 *
 * Priority: tmux > iterm2 > process > in_process
 */
class BackendRegistry
{
    private static ?BackendRegistry $instance = null;
    private ?BackendType $detectedType = null;
    private array $backends = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Auto-detect the best available backend.
     * Priority: tmux > iterm2 > process > in_process
     * Result is cached after first detection.
     */
    public function detect(): BackendType
    {
        if ($this->detectedType !== null) {
            return $this->detectedType;
        }

        // Check tmux
        if (TmuxBackend::detect()) {
            $this->detectedType = BackendType::TMUX;
            return $this->detectedType;
        }

        // Check iTerm2
        if (ITermBackend::detect()) {
            $this->detectedType = BackendType::ITERM2;
            return $this->detectedType;
        }

        // Default to process
        $this->detectedType = BackendType::PROCESS;
        return $this->detectedType;
    }

    /**
     * Get a backend instance by type.
     * Instances are cached and reused.
     */
    public function get(BackendType $type): BackendInterface
    {
        if (!isset($this->backends[$type->value])) {
            $this->backends[$type->value] = match ($type) {
                BackendType::IN_PROCESS => new InProcessBackend(),
                BackendType::PROCESS => new ProcessBackend(),
                BackendType::TMUX => new TmuxBackend(),
                BackendType::ITERM2 => new ITermBackend(),
                default => new ProcessBackend(), // fallback for DOCKER, REMOTE, etc.
            };
        }
        return $this->backends[$type->value];
    }

    /**
     * Get the auto-detected backend instance.
     */
    public function getDetected(): BackendInterface
    {
        return $this->get($this->detect());
    }

    /**
     * Health check all available backends.
     *
     * @return array<string, array{available: bool, type?: string, error?: string}>
     */
    public function healthCheck(): array
    {
        $results = [];
        foreach (BackendType::cases() as $type) {
            try {
                $backend = $this->get($type);
                $results[$type->value] = [
                    'available' => $backend->isAvailable(),
                    'type' => $type->value,
                ];
            } catch (\Throwable $e) {
                $results[$type->value] = [
                    'available' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /**
     * Get all available backend types.
     *
     * @return BackendType[]
     */
    public function getAvailableTypes(): array
    {
        return array_values(array_filter(
            BackendType::cases(),
            function (BackendType $type): bool {
                try {
                    return $this->get($type)->isAvailable();
                } catch (\Throwable) {
                    return false;
                }
            }
        ));
    }

    /**
     * Reset cached detection (for testing).
     */
    public function reset(): void
    {
        $this->detectedType = null;
        $this->backends = [];
    }

    /**
     * Reset singleton (for testing).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
