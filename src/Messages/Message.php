<?php

namespace SuperAgent\Messages;

use SuperAgent\Enums\Role;

abstract class Message
{
    public readonly string $id;

    public readonly string $timestamp;

    public function __construct(
        public readonly Role $role,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->timestamp = date('c');
    }

    /**
     * Convert to the provider-agnostic array format used internally.
     */
    abstract public function toArray(): array;
}
