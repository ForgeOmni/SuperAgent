<?php

declare(strict_types=1);

namespace SuperAgent\Bridge\Enhancers;

use SuperAgent\CostCalculator;
use SuperAgent\Exceptions\SuperAgentException;
use SuperAgent\Messages\AssistantMessage;

/**
 * Tracks per-request cost and enforces a USD budget limit.
 *
 * Adds cost metadata to each response and rejects requests that
 * would exceed the configured budget.
 */
class CostTrackingEnhancer implements EnhancerInterface
{
    private float $totalCostUsd = 0.0;

    private float $maxBudgetUsd;

    private ?string $currentModel = null;

    public function __construct(?float $maxBudgetUsd = null)
    {
        $this->maxBudgetUsd = $maxBudgetUsd
            ?? (function_exists('config') ? (float) config('superagent.agent.max_budget_usd', 0) : 0.0);
    }

    public function enhanceRequest(
        array &$messages,
        array &$tools,
        ?string &$systemPrompt,
        array &$options,
    ): void {
        // Check budget before sending request
        if ($this->maxBudgetUsd > 0 && $this->totalCostUsd >= $this->maxBudgetUsd) {
            throw new SuperAgentException(
                "Bridge budget exhausted: \${$this->totalCostUsd} >= \${$this->maxBudgetUsd}"
            );
        }

        $this->currentModel = $options['model'] ?? null;
    }

    public function enhanceResponse(AssistantMessage $message): AssistantMessage
    {
        if ($message->usage !== null && $this->currentModel !== null) {
            $cost = CostCalculator::calculate($this->currentModel, $message->usage);
            $this->totalCostUsd += $cost;

            $message->metadata['bridge_request_cost_usd'] = round($cost, 6);
            $message->metadata['bridge_total_cost_usd'] = round($this->totalCostUsd, 6);
            $message->metadata['bridge_input_tokens'] = $message->usage->inputTokens;
            $message->metadata['bridge_output_tokens'] = $message->usage->outputTokens;
        }

        return $message;
    }

    public function getTotalCostUsd(): float
    {
        return $this->totalCostUsd;
    }

    public function reset(): void
    {
        $this->totalCostUsd = 0.0;
    }
}
