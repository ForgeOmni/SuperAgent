<?php

namespace SuperAgent\IncrementalContext;

/**
 * Manages creation and retrieval of context checkpoints.
 */
class CheckpointManager
{
    private array $checkpoints = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_checkpoints' => 10,
        ], $config);
    }

    public function create(array $context, string $type, array $statistics = []): Checkpoint
    {
        $checkpoint = new Checkpoint($context, $type, $statistics);
        $this->checkpoints[$checkpoint->getId()] = $checkpoint;

        // Prune old checkpoints
        $this->cleanup($this->config['max_checkpoints']);

        return $checkpoint;
    }

    public function get(string $id): ?Checkpoint
    {
        return $this->checkpoints[$id] ?? null;
    }

    public function cleanup(int $maxKeep): void
    {
        if (count($this->checkpoints) <= $maxKeep) {
            return;
        }

        // Sort by timestamp descending
        $sorted = $this->checkpoints;
        uasort($sorted, fn(Checkpoint $a, Checkpoint $b) => $b->getTimestamp() - $a->getTimestamp());

        $this->checkpoints = array_slice($sorted, 0, $maxKeep, true);
    }

    public function all(): array
    {
        return $this->checkpoints;
    }
}
