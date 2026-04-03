<?php

declare(strict_types=1);

namespace SuperAgent\SkillDistillation;

/**
 * A skill distilled from a successful agent execution trace.
 *
 * Contains the generated Markdown skill template, metadata about
 * the source execution, and the target model recommendation.
 */
class DistilledSkill
{
    /**
     * @param string $id Unique identifier (hash-based)
     * @param string $name Skill name (slugified from prompt)
     * @param string $description Human-readable description
     * @param string $category Skill category
     * @param string $sourceModel Model that originally executed the task
     * @param string $targetModel Recommended cheaper model for replay
     * @param string[] $requiredTools Tools needed by this skill
     * @param string $template The generated Markdown skill content (frontmatter + body)
     * @param string[] $parameters Detected template parameters
     * @param int $sourceSteps Number of tool calls in original execution
     * @param float $sourceCostUsd Cost of the original execution
     * @param float $estimatedSavingsPct Estimated cost savings vs. source model
     * @param int $usageCount How many times this skill has been used
     * @param string $createdAt ISO 8601 timestamp
     * @param string|null $lastUsedAt ISO 8601 timestamp
     * @param array $metadata Additional metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $category,
        public readonly string $sourceModel,
        public readonly string $targetModel,
        public readonly array $requiredTools,
        public readonly string $template,
        public readonly array $parameters,
        public readonly int $sourceSteps,
        public readonly float $sourceCostUsd,
        public readonly float $estimatedSavingsPct,
        public int $usageCount = 0,
        public readonly string $createdAt = '',
        public ?string $lastUsedAt = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Record a usage of this skill.
     */
    public function recordUsage(): void
    {
        $this->usageCount++;
        $this->lastUsedAt = date('c');
    }

    /**
     * Generate a stable ID from the skill name.
     */
    public static function generateId(string $name): string
    {
        return substr(md5("distilled:{$name}"), 0, 12);
    }

    /**
     * Human-readable summary.
     */
    public function describe(): string
    {
        $savings = round($this->estimatedSavingsPct);

        return "{$this->name} [{$this->sourceModel} → {$this->targetModel}] "
            . "— {$this->sourceSteps} steps, ~{$savings}% savings, used {$this->usageCount}x";
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'source_model' => $this->sourceModel,
            'target_model' => $this->targetModel,
            'required_tools' => $this->requiredTools,
            'template' => $this->template,
            'parameters' => $this->parameters,
            'source_steps' => $this->sourceSteps,
            'source_cost_usd' => $this->sourceCostUsd,
            'estimated_savings_pct' => $this->estimatedSavingsPct,
            'usage_count' => $this->usageCount,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
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
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            category: $data['category'] ?? 'distilled',
            sourceModel: $data['source_model'] ?? '',
            targetModel: $data['target_model'] ?? '',
            requiredTools: $data['required_tools'] ?? [],
            template: $data['template'] ?? '',
            parameters: $data['parameters'] ?? [],
            sourceSteps: (int) ($data['source_steps'] ?? 0),
            sourceCostUsd: (float) ($data['source_cost_usd'] ?? 0.0),
            estimatedSavingsPct: (float) ($data['estimated_savings_pct'] ?? 0.0),
            usageCount: (int) ($data['usage_count'] ?? 0),
            createdAt: $data['created_at'] ?? date('c'),
            lastUsedAt: $data['last_used_at'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
