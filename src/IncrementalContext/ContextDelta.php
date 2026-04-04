<?php

namespace SuperAgent\IncrementalContext;

/**
 * Represents the difference between two context states.
 */
class ContextDelta
{
    private string $checkpoint = '';

    public function __construct(
        private array $added,
        private array $modified,
        private array $removed
    ) {}

    public function getAdded(): array
    {
        return $this->added;
    }

    public function getModified(): array
    {
        return $this->modified;
    }

    public function getRemoved(): array
    {
        return $this->removed;
    }

    public function getCheckpoint(): string
    {
        return $this->checkpoint;
    }

    public function setCheckpoint(string $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
    }

    public function isEmpty(): bool
    {
        return empty($this->added) && empty($this->modified) && empty($this->removed);
    }
}
