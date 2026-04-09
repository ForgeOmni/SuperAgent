<?php

declare(strict_types=1);

namespace SuperAgent\Middleware\Builtin;

use SuperAgent\Middleware\MiddlewareContext;
use SuperAgent\Middleware\MiddlewareInterface;
use SuperAgent\Middleware\MiddlewareResult;
use SuperAgent\Exceptions\BudgetExceededException;

/**
 * Tracks cumulative cost and enforces budget limits.
 */
class CostTrackingMiddleware implements MiddlewareInterface
{
    private float $totalCost = 0.0;
    private int $totalRequests = 0;

    /** @var array<string, float> Per-model pricing (USD per 1M input tokens) */
    private const MODEL_PRICING = [
        'claude-opus-4' => 15.0,
        'claude-sonnet-4' => 3.0,
        'claude-haiku-4' => 0.80,
        'gpt-4o' => 2.50,
        'gpt-4o-mini' => 0.15,
    ];

    public function __construct(
        private float $budgetUsd = 0.0, // 0 = unlimited
    ) {}

    public function name(): string
    {
        return 'cost_tracking';
    }

    public function priority(): int
    {
        return 80;
    }

    public function handle(MiddlewareContext $context, callable $next): MiddlewareResult
    {
        // Pre-check budget
        if ($this->budgetUsd > 0 && $this->totalCost >= $this->budgetUsd) {
            throw new BudgetExceededException(
                spent: $this->totalCost,
                budget: $this->budgetUsd,
            );
        }

        $start = microtime(true);
        $result = $next($context);
        $duration = microtime(true) - $start;

        // Calculate cost from usage
        $cost = $this->calculateCost($context, $result);
        $this->totalCost += $cost;
        $this->totalRequests++;

        // Post-check budget
        if ($this->budgetUsd > 0 && $this->totalCost > $this->budgetUsd) {
            throw new BudgetExceededException(
                spent: $this->totalCost,
                budget: $this->budgetUsd,
            );
        }

        return $result
            ->withMetadata('cost_usd', $cost)
            ->withMetadata('total_cost_usd', $this->totalCost)
            ->withMetadata('duration_ms', (int) ($duration * 1000));
    }

    public function getTotalCost(): float
    {
        return $this->totalCost;
    }

    public function getTotalRequests(): int
    {
        return $this->totalRequests;
    }

    public function resetTracking(): void
    {
        $this->totalCost = 0.0;
        $this->totalRequests = 0;
    }

    private function calculateCost(MiddlewareContext $context, MiddlewareResult $result): float
    {
        $usage = $result->usage;
        if (empty($usage)) {
            return 0.0;
        }

        $model = $context->options['model'] ?? 'claude-sonnet-4';
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;

        // Find matching price (prefix match)
        $inputPrice = 3.0; // default
        foreach (self::MODEL_PRICING as $prefix => $price) {
            if (str_starts_with($model, $prefix)) {
                $inputPrice = $price;
                break;
            }
        }

        // Output tokens typically cost 3-5x input
        $outputPrice = $inputPrice * 5;

        return ($inputTokens * $inputPrice + $outputTokens * $outputPrice) / 1_000_000;
    }
}
