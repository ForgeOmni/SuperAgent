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
    ) {
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
        foreach ($this->allResponses as $response) {
            if ($response->usage) {
                $input += $response->usage->inputTokens;
                $output += $response->usage->outputTokens;
            }
        }

        return new Usage($input, $output);
    }
}
