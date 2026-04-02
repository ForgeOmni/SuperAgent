<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\Messages\AssistantMessage;

/**
 * Tracks token usage across requests and detects diminishing returns.
 *
 * Adds usage metadata to response headers and can inject continuation
 * hints when the model is under-utilizing its token budget.
 */
class TokenBudgetEnhancer implements EnhancerInterface
{
    /** Percentage of budget that signals completion */
    private const COMPLETION_THRESHOLD = 0.9;

    /** Minimum token delta to consider meaningful progress */
    private const DIMINISHING_THRESHOLD = 500;

    private int $totalOutputTokens = 0;

    private int $continuationCount = 0;

    private int $lastDelta = 0;

    private int $prevDelta = 0;

    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        // Track continuation count
        $this->continuationCount++;
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        $outputTokens = $message->usage?->outputTokens ?? 0;

        $this->prevDelta = $this->lastDelta;
        $this->lastDelta = $outputTokens;
        $this->totalOutputTokens += $outputTokens;

        // Detect diminishing returns
        if ($this->continuationCount >= 3
            && $this->lastDelta < self::DIMINISHING_THRESHOLD
            && $this->prevDelta < self::DIMINISHING_THRESHOLD
        ) {
            // Inject a metadata hint (consumers can check this)
            $message->metadata['bridge_diminishing_returns'] = true;
            $message->metadata['bridge_continuation_count'] = $this->continuationCount;
        }

        $message->metadata['bridge_total_output_tokens'] = $this->totalOutputTokens;

        return $message;
    }

    public function getTotalOutputTokens(): int
    {
        return $this->totalOutputTokens;
    }

    public function reset(): void
    {
        $this->totalOutputTokens = 0;
        $this->continuationCount = 0;
        $this->lastDelta = 0;
        $this->prevDelta = 0;
    }
}
