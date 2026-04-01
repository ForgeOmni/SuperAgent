<?php

declare(strict_types=1);

namespace SuperAgent\Swarm;

/**
 * Base class for structured messages for agent communication.
 */
abstract class StructuredMessage
{
    abstract public function getType(): string;
    abstract public function toArray(): array;
}