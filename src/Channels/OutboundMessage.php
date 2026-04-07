<?php

namespace SuperAgent\Channels;

class OutboundMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $chatId,
        public readonly string $content,
        public readonly ?string $replyToMessageId = null,
        public readonly array $media = [],
        public readonly array $metadata = [],
    ) {}
}
