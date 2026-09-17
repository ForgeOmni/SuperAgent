<?php

namespace SuperAgent;

use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\Usage;

class AgentResult
{
    public function __construct(
        public readonly ?AssistantMessage $message,
        /** @var AssistantMessage[] */
        public readonly array $allResponses = [],
        /** @var Message[] */
        public readonly array $messages = [],
        public readonly float $totalCostUsd = 0.0,
        /**
         * Caller-supplied idempotency key forwarded from `Agent::run()`
         * options. Pure passthrough — the SDK itself does not persist or
         * dedupe on it. Hosts that write `ai_usage_logs` (or any similar
         * ledger) read this on the result to implement a dedup window, so
         * parallel workers retrying the same logical turn don't double-charge.
         * Null when the caller didn't supply one.
         */
        public readonly ?string $idempotencyKey = null,
        /**
         * Set when the turn stopped to wait for a human: everything needed to
         * finish it later, serialisable, in this process or another one.
         *
         * @since 1.2.0
         */
        public readonly ?\SuperAgent\Resume\ResumeEnvelope $resume = null,
    ) {
    }

    /**
     * Whether this turn ended waiting on an answer from outside the process
     * rather than on the model.
     *
     * @since 1.2.0
     */
    public function isAwaitingHuman(): bool
    {
        return $this->resume !== null;
    }

    /**
     * The tool calls that are waiting, with the metadata the tool attached —
     * what a host shows the person who has to decide.
     *
     * @return list<\SuperAgent\Resume\Deferral>
     *
     * @since 1.2.0
     */
    public function deferrals(): array
    {
        return $this->resume?->awaiting() ?? [];
    }

    /**
     * Get the final text response.
     */
    public function text(): string
    {
        return $this->message?->text() ?? '';
    }

    /**
     * Get the total number of turns (API calls) in this run.
     */
    public function turns(): int
    {
        return count($this->allResponses);
    }

    /**
     * Get aggregated token usage across all turns.
     */
    public function totalUsage(): Usage
    {
        $input = 0;
        $output = 0;
        $cacheCreation = 0;
        $cacheRead = 0;
        foreach ($this->allResponses as $response) {
            if ($response->usage) {
                $input += $response->usage->inputTokens;
                $output += $response->usage->outputTokens;
                $cacheCreation += $response->usage->cacheCreationInputTokens ?? 0;
                $cacheRead += $response->usage->cacheReadInputTokens ?? 0;
            }
        }

        return new Usage(
            $input,
            $output,
            $cacheCreation > 0 ? $cacheCreation : null,
            $cacheRead > 0 ? $cacheRead : null,
        );
    }
}
