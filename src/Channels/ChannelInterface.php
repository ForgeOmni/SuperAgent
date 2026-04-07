<?php

namespace SuperAgent\Channels;

interface ChannelInterface
{
    public function getName(): string;
    public function isEnabled(): bool;
    public function isAllowed(string $senderId): bool;
    public function start(): void;
    public function stop(): void;
    public function send(OutboundMessage $message): bool;
}
