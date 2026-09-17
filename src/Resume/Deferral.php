<?php

declare(strict_types=1);

namespace SuperAgent\Resume;

/**
 * One tool call that is waiting on an answer from outside this process.
 *
 * @since 1.4.0
 */
final class Deferral
{
    public function __construct(
        public readonly string $ticketId,
        public readonly string $toolUseId,
        public readonly string $toolName,
        public readonly array $meta = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['ticket_id'] ?? ''),
            (string) ($data['tool_use_id'] ?? ''),
            (string) ($data['tool_name'] ?? ''),
            (array) ($data['meta'] ?? []),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'tool_use_id' => $this->toolUseId,
            'tool_name' => $this->toolName,
            'meta' => $this->meta,
        ];
    }
}
