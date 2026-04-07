<?php

namespace SuperAgent\Channels;

class InboundMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $senderId,
        public readonly string $chatId,
        public readonly string $content,
        public readonly string $timestamp,
        public readonly ?string $sessionKey = null,
        public readonly array $media = [],
        public readonly array $metadata = [],
    ) {}

    public function getSessionKey(): string
    {
        return $this->sessionKey ?? "{$this->channel}:{$this->chatId}";
    }
}
