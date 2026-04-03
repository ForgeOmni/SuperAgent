<?php

declare(strict_types=1);

namespace SuperAgent\AdaptiveFeedback;

/**
 * A recurring correction pattern detected from user denials/feedback.
 *
 * Tracks how many times a specific correction has occurred, along with
 * contextual information needed to generate guardrails rules or memories.
 */
class CorrectionPattern
{
    /**
     * @param string $id Unique identifier (hash of category+toolName+pattern)
     * @param CorrectionCategory $category Type of correction
     * @param string $pattern Normalized description of what was corrected
     * @param string|null $toolName Tool that triggered the correction (if applicable)
     * @param string|null $toolInput Relevant input context (e.g., command pattern)
     * @param int $occurrences Number of times this pattern has occurred
     * @param string[] $reasons Raw denial/correction reasons from user
     * @param string|null $firstSeenAt ISO 8601 timestamp
     * @param string|null $lastSeenAt ISO 8601 timestamp
     * @param bool $promoted Whether this pattern has been promoted to a rule/memory
     * @param string|null $promotedTo What it was promoted to ('rule', 'memory', or null)
     * @param array $metadata Additional context
     */
    public function __construct(
        public readonly string $id,
        public readonly CorrectionCategory $category,
        public readonly string $pattern,
        public readonly ?string $toolName = null,
        public readonly ?string $toolInput = null,
        public int $occurrences = 1,
        public array $reasons = [],
        public ?string $firstSeenAt = null,
        public ?string $lastSeenAt = null,
        public bool $promoted = false,
        public ?string $promotedTo = null,
        public array $metadata = [],
    ) {
        $this->firstSeenAt ??= date('c');
        $this->lastSeenAt ??= date('c');
    }

    /**
     * Record another occurrence of this pattern.
     */
    public function recordOccurrence(string $reason): void
    {
        $this->occurrences++;
        $this->lastSeenAt = date('c');

        // Keep last 10 unique reasons
        if (!in_array($reason, $this->reasons, true)) {
            $this->reasons[] = $reason;
            if (count($this->reasons) > 10) {
                array_shift($this->reasons);
            }
        }
    }

    /**
     * Mark this pattern as promoted to a rule or memory.
     */
    public function markPromoted(string $type): void
    {
        $this->promoted = true;
        $this->promotedTo = $type;
    }

    /**
     * Generate a stable ID from the pattern components.
     */
    public static function generateId(CorrectionCategory $category, ?string $toolName, string $pattern): string
    {
        return substr(md5("{$category->value}:{$toolName}:{$pattern}"), 0, 12);
    }

    /**
     * Serialize to array for JSON storage.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'pattern' => $this->pattern,
            'tool_name' => $this->toolName,
            'tool_input' => $this->toolInput,
            'occurrences' => $this->occurrences,
            'reasons' => $this->reasons,
            'first_seen_at' => $this->firstSeenAt,
            'last_seen_at' => $this->lastSeenAt,
            'promoted' => $this->promoted,
            'promoted_to' => $this->promotedTo,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Deserialize from array.
     */
    public static function fromArray(array $data): self
    {
        $category = CorrectionCategory::tryFrom($data['category'] ?? '');
        if ($category === null) {
            $category = CorrectionCategory::BEHAVIOR_CORRECTION;
        }

        return new self(
            id: $data['id'] ?? '',
            category: $category,
            pattern: $data['pattern'] ?? '',
            toolName: $data['tool_name'] ?? null,
            toolInput: $data['tool_input'] ?? null,
            occurrences: (int) ($data['occurrences'] ?? 1),
            reasons: $data['reasons'] ?? [],
            firstSeenAt: $data['first_seen_at'] ?? null,
            lastSeenAt: $data['last_seen_at'] ?? null,
            promoted: (bool) ($data['promoted'] ?? false),
            promotedTo: $data['promoted_to'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Human-readable summary for listing.
     */
    public function describe(): string
    {
        $tool = $this->toolName ? " [{$this->toolName}]" : '';
        $status = $this->promoted ? " (→ {$this->promotedTo})" : '';

        return "{$this->pattern}{$tool} — {$this->occurrences}x{$status}";
    }
}
