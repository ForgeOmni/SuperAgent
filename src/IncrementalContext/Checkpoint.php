<?php

namespace SuperAgent\IncrementalContext;

/**
 * Represents a snapshot of context at a point in time.
 */
class Checkpoint
{
    private string $id;
    private array $context;
    private string $type;
    private int $timestamp;
    private array $statistics;

    public function __construct(array $context, string $type, array $statistics = [])
    {
        $this->id = uniqid('cp_', true);
        $this->context = $context;
        $this->type = $type;
        $this->timestamp = time();
        $this->statistics = $statistics;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getStatistics(): array
    {
        return $this->statistics;
    }
}
