<?php

declare(strict_types=1);

namespace SuperAgent\Resume;

use SuperAgent\Exceptions\ResumeException;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\MessageSerializer;
use SuperAgent\Tools\ToolResult;

/**
 * Everything needed to finish a turn that stopped to wait for a human.
 *
 * The SDK keeps no state between `run()` and `resume()`: this object is the
 * state, and it serialises, so the host can put it in a row, a queue message
 * or a file and pick the turn up in another process, after a deploy, hours
 * later. That is the point — a human-in-the-loop approval that only works
 * inside one long-lived PHP process is not a human-in-the-loop approval.
 *
 * It holds the transcript, the tool results that *did* complete in the same
 * turn (providers require an answer for every tool call in an assistant
 * message, so the completed ones wait with the pending one), the pending
 * tickets and the answers gathered so far.
 *
 * @since 1.4.0
 */
final class ResumeEnvelope
{
    public const VERSION = 1;

    /**
     * @param Message[]                                                  $messages
     * @param list<array{tool_use_id:string,content:string,is_error:bool}> $completedResults
     * @param list<Deferral>                                             $pending
     * @param array<string,array{tool_use_id:string,content:string,is_error:bool}> $resolved keyed by ticket
     */
    public function __construct(
        public readonly string $id,
        public readonly array $messages,
        public readonly array $completedResults,
        public readonly array $pending,
        public readonly array $resolved = [],
        public readonly ?string $providerName = null,
        public readonly ?string $model = null,
        public readonly int $turnCount = 0,
        public readonly float $totalCostUsd = 0.0,
        public readonly ?string $createdAt = null,
        public readonly ?string $expiresAt = null,
    ) {
    }

    /** @return list<Deferral> tickets still waiting for an answer */
    public function awaiting(): array
    {
        return array_values(array_filter(
            $this->pending,
            fn (Deferral $d): bool => ! isset($this->resolved[$d->ticketId])
        ));
    }

    public function isReady(): bool
    {
        return $this->awaiting() === [];
    }

    public function hasTicket(string $ticketId): bool
    {
        foreach ($this->pending as $deferral) {
            if ($deferral->ticketId === $ticketId) {
                return true;
            }
        }

        return false;
    }

    public function isExpired(?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? time()) > strtotime($this->expiresAt);
    }

    /**
     * Records one answer, and returns the envelope that now carries it.
     *
     * Refuses an unknown ticket, a ticket already answered, an expired
     * envelope, and an answer that is itself deferred — a tool may hand a
     * decision to a human, but a human may not hand it back.
     */
    public function withAnswer(string $ticketId, ToolResult $result): self
    {
        if ($this->isExpired()) {
            throw new ResumeException("This resume envelope expired at {$this->expiresAt}.");
        }

        if (! $this->hasTicket($ticketId)) {
            throw new ResumeException("Ticket '{$ticketId}' is not pending in this envelope.");
        }

        if (isset($this->resolved[$ticketId])) {
            throw new ResumeException("Ticket '{$ticketId}' has already been answered.");
        }

        if ($result->isDeferred()) {
            throw new ResumeException("Ticket '{$ticketId}' cannot be answered with another deferral.");
        }

        $deferral = $this->deferralFor($ticketId);

        $resolved = $this->resolved;
        $resolved[$ticketId] = [
            'tool_use_id' => $deferral->toolUseId,
            'content' => $result->contentAsString(),
            'is_error' => $result->isError,
        ];

        return new self(
            $this->id,
            $this->messages,
            $this->completedResults,
            $this->pending,
            $resolved,
            $this->providerName,
            $this->model,
            $this->turnCount,
            $this->totalCostUsd,
            $this->createdAt,
            $this->expiresAt,
        );
    }

    /**
     * Every tool result for the interrupted assistant message, in the order
     * the model asked for them. A provider refuses a transcript where one
     * tool call has no answer, so this is assembled only once the envelope is
     * ready.
     *
     * @return list<array{tool_use_id:string,content:string,is_error:bool}>
     */
    public function toolResults(): array
    {
        if (! $this->isReady()) {
            throw new ResumeException(
                'This envelope still has unanswered tickets: '
                . implode(', ', array_map(fn (Deferral $d): string => $d->ticketId, $this->awaiting()))
                . '.'
            );
        }

        $byToolUseId = [];
        foreach ($this->completedResults as $result) {
            $byToolUseId[$result['tool_use_id']] = $result;
        }
        foreach ($this->resolved as $result) {
            $byToolUseId[$result['tool_use_id']] = $result;
        }

        $ordered = [];
        foreach ($this->toolUseIdsInOrder() as $toolUseId) {
            if (isset($byToolUseId[$toolUseId])) {
                $ordered[] = $byToolUseId[$toolUseId];
                unset($byToolUseId[$toolUseId]);
            }
        }

        return array_merge($ordered, array_values($byToolUseId));
    }

    public function deferralFor(string $ticketId): Deferral
    {
        foreach ($this->pending as $deferral) {
            if ($deferral->ticketId === $ticketId) {
                return $deferral;
            }
        }

        throw new ResumeException("Ticket '{$ticketId}' is not pending in this envelope.");
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'id' => $this->id,
            'provider' => $this->providerName,
            'model' => $this->model,
            'turn_count' => $this->turnCount,
            'total_cost_usd' => $this->totalCostUsd,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'messages' => MessageSerializer::encodeAll($this->messages),
            'completed_results' => $this->completedResults,
            'pending' => array_map(fn (Deferral $d): array => $d->toArray(), $this->pending),
            'resolved' => $this->resolved,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $version = (int) ($data['version'] ?? 0);
        if ($version !== self::VERSION) {
            throw new ResumeException(
                "Unsupported resume envelope version {$version}; this SDK writes version " . self::VERSION . '.'
            );
        }

        return new self(
            (string) ($data['id'] ?? ''),
            MessageSerializer::decodeAll($data['messages'] ?? []),
            array_values($data['completed_results'] ?? []),
            array_map([Deferral::class, 'fromArray'], $data['pending'] ?? []),
            (array) ($data['resolved'] ?? []),
            $data['provider'] ?? null,
            $data['model'] ?? null,
            (int) ($data['turn_count'] ?? 0),
            (float) ($data['total_cost_usd'] ?? 0.0),
            $data['created_at'] ?? null,
            $data['expires_at'] ?? null,
        );
    }

    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        $json = json_encode($this->toArray(), $flags);

        if ($json === false) {
            throw new ResumeException('Could not serialise the resume envelope: ' . json_last_error_msg());
        }

        return $json;
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new ResumeException('Could not read the resume envelope: ' . json_last_error_msg());
        }

        return self::fromArray($data);
    }

    /**
     * The tool_use ids of the last assistant message, which is the one whose
     * answers are outstanding.
     *
     * @return list<string>
     */
    private function toolUseIdsInOrder(): array
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $message = $this->messages[$i];
            if (! $message instanceof \SuperAgent\Messages\AssistantMessage) {
                continue;
            }

            $ids = [];
            foreach ($message->toolUseBlocks() as $block) {
                $ids[] = (string) $block->toolUseId;
            }

            return $ids;
        }

        return [];
    }
}
