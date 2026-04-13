<?php

namespace SuperAgent\Telemetry;

use Illuminate\Support\Collection;
use SuperAgent\Support\DateTime as Carbon;

class CostTracker
{
    private static ?self $instance = null;
    private Collection $costs;
    private Collection $sessionCosts;
    private array $modelPricing;
    private bool $enabled;

    public function __construct()
    {
        $this->costs = collect();
        $this->sessionCosts = collect();
        $this->enabled = config('superagent.telemetry.enabled', false)
            && config('superagent.telemetry.cost_tracking.enabled', false);
        $this->loadModelPricing();
    }

    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load model pricing configuration.
     */
    private function loadModelPricing(): void
    {
        // Default pricing per 1M tokens (in USD)
        $this->modelPricing = config('superagent.telemetry.model_pricing', [
            'claude-3-opus' => ['input' => 15.0, 'output' => 75.0],
            'claude-3-sonnet' => ['input' => 3.0, 'output' => 15.0],
            'claude-3-haiku' => ['input' => 0.25, 'output' => 1.25],
            'claude-3.5-sonnet' => ['input' => 3.0, 'output' => 15.0],
            'gpt-4' => ['input' => 30.0, 'output' => 60.0],
            'gpt-4-turbo' => ['input' => 10.0, 'output' => 30.0],
            'gpt-3.5-turbo' => ['input' => 0.5, 'output' => 1.5],
            'gemini-pro' => ['input' => 0.5, 'output' => 1.5],
            'gemini-ultra' => ['input' => 7.0, 'output' => 21.0],
        ]);
    }

    /**
     * Track LLM usage cost.
     */
    public function trackLLMUsage(
        string $model,
        int $inputTokens,
        int $outputTokens,
        string $sessionId = null,
        array $metadata = []
    ): float {
        if (!$this->enabled) {
            return 0.0;
        }

        $cost = $this->calculateLLMCost($model, $inputTokens, $outputTokens);

        $record = [
            'timestamp' => date('c'),
            'type' => 'llm',
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'cost_usd' => $cost,
            'session_id' => $sessionId,
            'metadata' => $metadata,
        ];

        $this->costs->push($record);

        // Track session costs
        if ($sessionId) {
            $current = $this->sessionCosts->get($sessionId, [
                'total_cost' => 0.0,
                'total_input_tokens' => 0,
                'total_output_tokens' => 0,
                'request_count' => 0,
                'models_used' => [],
            ]);

            $current['total_cost'] += $cost;
            $current['total_input_tokens'] += $inputTokens;
            $current['total_output_tokens'] += $outputTokens;
            $current['request_count'] += 1;
            
            if (!in_array($model, $current['models_used'])) {
                $current['models_used'][] = $model;
            }

            $this->sessionCosts->put($sessionId, $current);
        }

        return $cost;
    }

    /**
     * Track tool execution cost (if applicable).
     */
    public function trackToolUsage(
        string $toolName,
        float $executionTime,
        string $sessionId = null,
        array $metadata = []
    ): float {
        if (!$this->enabled) {
            return 0.0;
        }

        // Some tools might have associated costs (e.g., API calls)
        $cost = $this->calculateToolCost($toolName, $executionTime, $metadata);

        if ($cost > 0) {
            $record = [
                'timestamp' => date('c'),
                'type' => 'tool',
                'tool' => $toolName,
                'execution_time_ms' => $executionTime,
                'cost_usd' => $cost,
                'session_id' => $sessionId,
                'metadata' => $metadata,
            ];

            $this->costs->push($record);

            // Update session costs
            if ($sessionId) {
                $current = $this->sessionCosts->get($sessionId, ['total_cost' => 0.0]);
                $current['total_cost'] += $cost;
                $this->sessionCosts->put($sessionId, $current);
            }
        }

        return $cost;
    }

    /**
     * Calculate LLM cost based on model and tokens.
     */
    private function calculateLLMCost(string $model, int $inputTokens, int $outputTokens): float
    {
        // Check for exact model match first
        $pricing = $this->modelPricing[$model] ?? null;

        // Try to find a matching model by prefix
        if (!$pricing) {
            foreach ($this->modelPricing as $modelKey => $modelPricing) {
                if (str_starts_with($model, $modelKey)) {
                    $pricing = $modelPricing;
                    break;
                }
            }
        }

        // Default to zero cost if model not found
        if (!$pricing) {
            logger()->warning("Unknown model for cost calculation: {$model}");
            return 0.0;
        }

        $inputCost = ($inputTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($outputTokens / 1_000_000) * $pricing['output'];

        return round($inputCost + $outputCost, 6);
    }

    /**
     * Calculate tool cost (if applicable).
     */
    private function calculateToolCost(string $toolName, float $executionTime, array $metadata): float
    {
        // Tool-specific costs (e.g., external API calls)
        $toolCosts = config('superagent.telemetry.tool_costs', [
            'web_search' => 0.001, // Per search
            'web_fetch' => 0.0005, // Per fetch
            'mcp_*' => 0.0001, // Per MCP call
        ]);

        foreach ($toolCosts as $pattern => $cost) {
            if ($pattern === $toolName || 
                (str_ends_with($pattern, '*') && str_starts_with($toolName, rtrim($pattern, '*')))) {
                return $cost;
            }
        }

        return 0.0;
    }

    /**
     * Get total costs for a session.
     */
    public function getSessionCosts(string $sessionId): array
    {
        return $this->sessionCosts->get($sessionId, [
            'total_cost' => 0.0,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'request_count' => 0,
            'models_used' => [],
        ]);
    }

    /**
     * Get cost summary.
     */
    public function getCostSummary(Carbon $startDate = null, Carbon $endDate = null): array
    {
        $filtered = $this->costs;

        if ($startDate) {
            $filtered = $filtered->filter(function ($cost) use ($startDate) {
                return Carbon::parse($cost['timestamp'])->gte($startDate);
            });
        }

        if ($endDate) {
            $filtered = $filtered->filter(function ($cost) use ($endDate) {
                return Carbon::parse($cost['timestamp'])->lte($endDate);
            });
        }

        $summary = [
            'total_cost' => $filtered->sum('cost_usd'),
            'by_type' => [],
            'by_model' => [],
            'by_session' => [],
            'record_count' => $filtered->count(),
            'period' => [
                'start' => $startDate?->toIso8601String(),
                'end' => $endDate?->toIso8601String(),
            ],
        ];

        // Group by type
        $summary['by_type'] = $filtered->groupBy('type')
            ->map(fn($group) => [
                'count' => $group->count(),
                'total_cost' => $group->sum('cost_usd'),
            ])
            ->toArray();

        // Group by model (LLM only)
        $llmCosts = $filtered->where('type', 'llm');
        $summary['by_model'] = $llmCosts->groupBy('model')
            ->map(fn($group) => [
                'count' => $group->count(),
                'total_cost' => $group->sum('cost_usd'),
                'total_tokens' => $group->sum('total_tokens'),
            ])
            ->toArray();

        // Top sessions by cost
        $summary['top_sessions'] = $this->sessionCosts
            ->sortByDesc('total_cost')
            ->take(10)
            ->toArray();

        return $summary;
    }

    /**
     * Export cost data.
     */
    public function export(string $format = 'json'): string
    {
        $data = [
            'export_timestamp' => date('c'),
            'costs' => $this->costs->toArray(),
            'session_costs' => $this->sessionCosts->toArray(),
            'summary' => $this->getCostSummary(),
        ];

        return match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->exportToCsv($data),
            default => json_encode($data),
        };
    }

    /**
     * Export to CSV format.
     */
    private function exportToCsv(array $data): string
    {
        $csv = "Timestamp,Type,Model/Tool,Input Tokens,Output Tokens,Cost USD,Session ID\n";

        foreach ($data['costs'] as $cost) {
            $csv .= implode(',', [
                $cost['timestamp'],
                $cost['type'],
                $cost['model'] ?? $cost['tool'] ?? '',
                $cost['input_tokens'] ?? 0,
                $cost['output_tokens'] ?? 0,
                $cost['cost_usd'],
                $cost['session_id'] ?? '',
            ]) . "\n";
        }

        return $csv;
    }

    /**
     * Clear cost data (for testing).
     */
    public static function clear(): void
    {
        if (self::$instance) {
            self::$instance->costs = collect();
            self::$instance->sessionCosts = collect();
        }
    }

    /**
     * Check if cost tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get model pricing.
     */
    public function getModelPricing(): array
    {
        return $this->modelPricing;
    }

    /**
     * Update model pricing.
     */
    public function updateModelPricing(string $model, float $inputPrice, float $outputPrice): void
    {
        $this->modelPricing[$model] = [
            'input' => $inputPrice,
            'output' => $outputPrice,
        ];
    }
}