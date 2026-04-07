<?php

namespace SuperAgent\Channels;

class ChannelManager
{
    /** @var ChannelInterface[] */
    private array $channels = [];

    public function __construct(private MessageBus $bus) {}

    public function register(ChannelInterface $channel): void
    {
        $this->channels[$channel->getName()] = $channel;
    }

    public function getChannel(string $name): ?ChannelInterface
    {
        return $this->channels[$name] ?? null;
    }

    public function getEnabledChannels(): array
    {
        return array_filter($this->channels, fn($ch) => $ch->isEnabled());
    }

    public function startAll(): void
    {
        foreach ($this->getEnabledChannels() as $channel) {
            try {
                $channel->start();
            } catch (\Throwable $e) {
                error_log("[SuperAgent] Failed to start channel {$channel->getName()}: {$e->getMessage()}");
            }
        }
    }

    public function stopAll(): void
    {
        foreach ($this->channels as $channel) {
            try {
                $channel->stop();
            } catch (\Throwable $e) {
                error_log("[SuperAgent] Failed to stop channel {$channel->getName()}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Dispatch outbound messages from bus to appropriate channels.
     */
    public function dispatchOutbound(): int
    {
        $count = 0;
        while ($msg = $this->bus->consumeOutbound()) {
            $channel = $this->channels[$msg->channel] ?? null;
            if ($channel && $channel->isEnabled()) {
                try {
                    $channel->send($msg);
                    $count++;
                } catch (\Throwable $e) {
                    error_log("[SuperAgent] Failed to send via {$msg->channel}: {$e->getMessage()}");
                }
            }
        }
        return $count;
    }

    public function getRegisteredNames(): array
    {
        return array_keys($this->channels);
    }

    public function getStatus(): array
    {
        $status = [];
        foreach ($this->channels as $name => $ch) {
            $status[$name] = ['enabled' => $ch->isEnabled()];
        }
        return $status;
    }

    /**
     * Factory: create from config array.
     */
    public static function fromConfig(array $config, MessageBus $bus): self
    {
        $manager = new self($bus);
        // Config format: 'channels' => ['slack' => [...], 'telegram' => [...]]
        // Each channel config has: enabled, allow_from, plus channel-specific fields
        // Channels are registered but not started until startAll()
        return $manager;
    }
}
