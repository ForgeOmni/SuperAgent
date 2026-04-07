<?php

namespace SuperAgent\Channels;

abstract class BaseChannel implements ChannelInterface
{
    protected bool $running = false;

    public function __construct(
        protected string $name,
        protected bool $enabled,
        protected array $allowFrom = [],  // empty = deny all, ['*'] = allow all
        protected ?MessageBus $bus = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isAllowed(string $senderId): bool
    {
        if (empty($this->allowFrom)) {
            return false;
        }
        if (in_array('*', $this->allowFrom, true)) {
            return true;
        }
        return in_array($senderId, $this->allowFrom, true);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    protected function handleMessage(
        string $senderId,
        string $chatId,
        string $content,
        array $media = [],
        array $metadata = [],
    ): void {
        if (!$this->isAllowed($senderId)) {
            error_log("[SuperAgent] Channel {$this->name}: denied message from {$senderId}");
            return;
        }
        $msg = new InboundMessage(
            channel: $this->name,
            senderId: $senderId,
            chatId: $chatId,
            content: $content,
            timestamp: date('c'),
            media: $media,
            metadata: $metadata,
        );
        $this->bus?->publishInbound($msg);
    }
}
