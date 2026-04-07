<?php

namespace SuperAgent\Channels;

/**
 * Decouples channels from the agent core.
 * In PHP we use SplQueue since we don't have asyncio queues.
 */
class MessageBus
{
    private \SplQueue $inbound;
    private \SplQueue $outbound;

    public function __construct()
    {
        $this->inbound = new \SplQueue();
        $this->outbound = new \SplQueue();
    }

    public function publishInbound(InboundMessage $msg): void
    {
        $this->inbound->enqueue($msg);
    }

    public function consumeInbound(): ?InboundMessage
    {
        return $this->inbound->isEmpty() ? null : $this->inbound->dequeue();
    }

    public function publishOutbound(OutboundMessage $msg): void
    {
        $this->outbound->enqueue($msg);
    }

    public function consumeOutbound(): ?OutboundMessage
    {
        return $this->outbound->isEmpty() ? null : $this->outbound->dequeue();
    }

    public function inboundSize(): int
    {
        return $this->inbound->count();
    }

    public function outboundSize(): int
    {
        return $this->outbound->count();
    }

    public function drainInbound(): array
    {
        $msgs = [];
        while (!$this->inbound->isEmpty()) {
            $msgs[] = $this->inbound->dequeue();
        }
        return $msgs;
    }

    public function drainOutbound(): array
    {
        $msgs = [];
        while (!$this->outbound->isEmpty()) {
            $msgs[] = $this->outbound->dequeue();
        }
        return $msgs;
    }
}
