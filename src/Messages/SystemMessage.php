<?php

namespace SuperAgent\Messages;

use SuperAgent\Enums\Role;

class SystemMessage extends Message
{
    public function __construct(
        public readonly string $content,
    ) {
        parent::__construct(Role::System);
    }

    public function toArray(): array
    {
        return [
            'role' => 'system',
            'content' => $this->content,
        ];
    }
}
