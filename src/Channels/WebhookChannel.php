<?php

namespace SuperAgent\Channels;

/**
 * Generic webhook channel: receives messages via HTTP POST, sends via HTTP POST.
 * Useful for custom integrations (Slack incoming webhooks, generic REST APIs, etc.)
 */
class WebhookChannel extends BaseChannel
{
    public function __construct(
        string $name,
        bool $enabled,
        array $allowFrom,
        ?MessageBus $bus,
        private string $outboundUrl,
        private array $outboundHeaders = [],
        private int $timeout = 10,
    ) {
        parent::__construct($name, $enabled, $allowFrom, $bus);
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function send(OutboundMessage $message): bool
    {
        // HTTP POST to outboundUrl with message payload
        $payload = json_encode([
            'chat_id' => $message->chatId,
            'content' => $message->content,
            'reply_to' => $message->replyToMessageId,
            'metadata' => $message->metadata,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => array_merge(
                    ['Content-Type: application/json'],
                    array_map(
                        fn($k, $v) => "{$k}: {$v}",
                        array_keys($this->outboundHeaders),
                        $this->outboundHeaders
                    )
                ),
                'content' => $payload,
                'timeout' => $this->timeout,
            ],
        ]);

        $result = @file_get_contents($this->outboundUrl, false, $context);
        if ($result === false) {
            error_log("[SuperAgent] WebhookChannel {$this->name}: POST to {$this->outboundUrl} failed");
            return false;
        }
        return true;
    }

    /**
     * Handle an incoming webhook request (called from an HTTP controller).
     */
    public function handleIncoming(array $payload): void
    {
        $senderId = $payload['sender_id'] ?? 'unknown';
        $chatId = $payload['chat_id'] ?? 'default';
        $content = $payload['content'] ?? '';
        $this->handleMessage($senderId, $chatId, $content, metadata: $payload);
    }
}
