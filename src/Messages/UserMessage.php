<?php

namespace SuperAgent\Messages;

use SuperAgent\Enums\Role;

class UserMessage extends Message
{
    public function __construct(
        public readonly string|array $content,
    ) {
        parent::__construct(Role::User);
    }

    public function toArray(): array
    {
        return [
            'role' => 'user',
            'content' => $this->content,
        ];
    }
}
