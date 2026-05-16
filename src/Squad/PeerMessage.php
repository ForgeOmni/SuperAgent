<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

/**
 * One message on the per-squad peer bus.
 *
 * The shape is deliberately minimal — peer-to-peer chatter is meant
 * to be terse coordination ("what auth scheme are you assuming?",
 * "FYI I picked PKCE not implicit"), not a chat log. Hosts that need
 * structured payloads should put them on the `Blackboard` and reference
 * the key.
 */
final class PeerMessage
{
    public const KIND_TELL  = 'tell';   // fire-and-forget
    public const KIND_ASK   = 'ask';    // synchronous question
    public const KIND_REPLY = 'reply';  // answer to a prior ask

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $body,
        public readonly string $kind = self::KIND_TELL,
        public readonly ?string $inReplyTo = null,
        public readonly float $createdAt = 0.0,
    ) {}

    public static function tell(string $from, string $to, string $body): self
    {
        return new self($from, $to, $body, self::KIND_TELL, null, microtime(true));
    }

    public static function ask(string $from, string $to, string $body): self
    {
        return new self($from, $to, $body, self::KIND_ASK, null, microtime(true));
    }

    public static function reply(string $from, string $to, string $body, string $inReplyTo): self
    {
        return new self($from, $to, $body, self::KIND_REPLY, $inReplyTo, microtime(true));
    }

    public function toArray(): array
    {
        return [
            'from'         => $this->from,
            'to'           => $this->to,
            'kind'         => $this->kind,
            'in_reply_to'  => $this->inReplyTo,
            'body'         => $this->body,
            'created_at'   => $this->createdAt,
        ];
    }
}
