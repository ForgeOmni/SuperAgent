<?php

declare(strict_types=1);

namespace SuperAgent\Harness;

class CommandDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly \Closure $handler,
    ) {}
}
