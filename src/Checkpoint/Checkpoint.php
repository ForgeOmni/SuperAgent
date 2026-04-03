<?php

declare(strict_types=1);

namespace SuperAgent\Checkpoint;

/**
 * Immutable snapshot of the QueryEngine's mutable state at a point in time.
 *
 * Captures everything needed to resume an agent run mid-flight:
 * messages, turn count, costs, token usage, and sub-component state.
 */
class Checkpoint
{
    /**
     * @param string $id Unique checkpoint ID
     * @param string $sessionId Session this checkpoint belongs to
     * @param array $messages Serialized messages (via MessageSerializer)
     * @param int $turnCount Current turn number
     * @param float $totalCostUsd Cumulative cost
     * @param int $turnOutputTokens Output tokens in current turn
     * @param array $budgetTrackerState TokenBudgetTracker state snapshot
     * @param array $collectorState RuntimeContextCollector state snapshot
     * @param string $model Model being used
     * @param string $prompt Original user prompt
     * @param string $createdAt ISO 8601 timestamp
     * @param array $metadata Additional metadata (options, config, etc.)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $sessionId,
        public readonly array $messages,
        public readonly int $turnCount,
        public readonly float $totalCostUsd,
        public readonly int $turnOutputTokens,
        public readonly array $budgetTrackerState,
        public readonly array $collectorState,
        public readonly string $model,
        public readonly string $prompt,
        public readonly string $createdAt,
        public readonly array $metadata = [],
    ) {}

    /**
     * Generate a checkpoint ID based on turn count (deterministic per session+turn).
     */
    public static function generateId(string $sessionId, int $turnCount): string
    {
        return substr(md5("{$sessionId}:{$turnCount}"), 0, 16);
    }

    /**
     * Human-readable summary.
     */
    public function describe(): string
    {
        $promptPreview = strlen($this->prompt) > 50
            ? substr($this->prompt, 0, 47) . '...'
            : $this->prompt;

        return "Turn {$this->turnCount} | \${$this->totalCostUsd} | {$this->model} | \"{$promptPreview}\"";
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'messages' => $this->messages,
            'turn_count' => $this->turnCount,
            'total_cost_usd' => $this->totalCostUsd,
            'turn_output_tokens' => $this->turnOutputTokens,
            'budget_tracker_state' => $this->budgetTrackerState,
            'collector_state' => $this->collectorState,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'created_at' => $this->createdAt,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Deserialize from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            sessionId: $data['session_id'] ?? '',
            messages: $data['messages'] ?? [],
            turnCount: (int) ($data['turn_count'] ?? 0),
            totalCostUsd: (float) ($data['total_cost_usd'] ?? 0.0),
            turnOutputTokens: (int) ($data['turn_output_tokens'] ?? 0),
            budgetTrackerState: $data['budget_tracker_state'] ?? [],
            collectorState: $data['collector_state'] ?? [],
            model: $data['model'] ?? '',
            prompt: $data['prompt'] ?? '',
            createdAt: $data['created_at'] ?? date('c'),
            metadata: $data['metadata'] ?? [],
        );
    }
}
