<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use SuperAgent\Support\DateTime as Carbon;

class Memory
{
    public readonly Carbon $createdAt;
    public readonly Carbon $updatedAt;
    private ?Carbon $accessedAt = null;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly MemoryType $type,
        public readonly string $content,
        public readonly MemoryScope $scope = MemoryScope::PRIVATE,
        Carbon|\DateTimeInterface|null $createdAt = null,
        Carbon|\DateTimeInterface|null $updatedAt = null,
        public readonly array $metadata = [],
    ) {
        $this->createdAt = self::ensureDateTime($createdAt);
        $this->updatedAt = self::ensureDateTime($updatedAt);
    }

    private static function ensureDateTime(Carbon|\DateTimeInterface|null $dt): Carbon
    {
        if ($dt === null) {
            return Carbon::now();
        }
        if ($dt instanceof Carbon) {
            return $dt;
        }
        return Carbon::parse($dt->format('Y-m-d H:i:s.u'));
    }
    
    /**
     * Create a memory from frontmatter and content
     */
    public static function fromMarkdown(string $id, array $frontmatter, string $content): self
    {
        return new self(
            id: $id,
            name: $frontmatter['name'] ?? $id,
            description: $frontmatter['description'] ?? '',
            type: MemoryType::from($frontmatter['type'] ?? 'project'),
            content: $content,
            scope: isset($frontmatter['scope']) ? MemoryScope::from($frontmatter['scope']) : MemoryScope::PRIVATE,
            createdAt: isset($frontmatter['created_at']) ? Carbon::parse($frontmatter['created_at']) : null,
            updatedAt: isset($frontmatter['updated_at']) ? Carbon::parse($frontmatter['updated_at']) : null,
            metadata: array_diff_key($frontmatter, array_flip(['name', 'description', 'type', 'scope', 'created_at', 'updated_at'])),
        );
    }
    
    /**
     * Convert to markdown format with frontmatter
     */
    public function toMarkdown(): string
    {
        $frontmatter = [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'scope' => $this->scope->value,
            'created_at' => $this->createdAt->toIso8601String(),
            'updated_at' => $this->updatedAt->toIso8601String(),
        ];
        
        if ($this->accessedAt !== null) {
            $frontmatter['accessed_at'] = $this->accessedAt->toIso8601String();
        }
        
        // Add metadata
        foreach ($this->metadata as $key => $value) {
            $frontmatter[$key] = $value;
        }
        
        $yaml = "---\n";
        foreach ($frontmatter as $key => $value) {
            $yaml .= "{$key}: {$this->formatYamlValue($value)}\n";
        }
        $yaml .= "---\n\n";
        
        return $yaml . $this->content;
    }
    
    /**
     * Format a value for YAML
     */
    private function formatYamlValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_numeric($value)) {
            return (string) $value;
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        // Quote strings that contain special characters
        if (str_contains($value, ':') || str_contains($value, "\n") || str_contains($value, '"')) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        
        return $value;
    }
    
    /**
     * Mark this memory as accessed
     */
    public function markAccessed(): void
    {
        $this->accessedAt = Carbon::now();
    }
    
    /**
     * Update this memory
     */
    public function update(string $content, ?string $description = null): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            description: $description ?? $this->description,
            type: $this->type,
            content: $content,
            scope: $this->scope,
            createdAt: $this->createdAt,
            updatedAt: Carbon::now(),
            metadata: $this->metadata,
        );
    }
    
    /**
     * Get the age of this memory in days
     */
    public function getAgeInDays(): int
    {
        return $this->createdAt->diffInDays(Carbon::now());
    }
    
    /**
     * Get the days since last access
     */
    public function getDaysSinceAccess(): ?int
    {
        if ($this->accessedAt === null) {
            return null;
        }
        
        return $this->accessedAt->diffInDays(Carbon::now());
    }
    
    /**
     * Check if this memory is stale (not accessed recently)
     */
    public function isStale(int $staleDays = 30): bool
    {
        $daysSinceAccess = $this->getDaysSinceAccess();
        
        // If never accessed, check creation date
        if ($daysSinceAccess === null) {
            return $this->getAgeInDays() > $staleDays;
        }
        
        return $daysSinceAccess > $staleDays;
    }
    
    /**
     * Get a truncated version of the content for display
     */
    public function getTruncatedContent(int $maxLength = 100): string
    {
        if (strlen($this->content) <= $maxLength) {
            return $this->content;
        }
        
        return substr($this->content, 0, $maxLength) . '...';
    }
    
    /**
     * Create an index entry for MEMORY.md
     */
    public function getIndexEntry(string $filename): string
    {
        $truncatedDesc = $this->description;
        if (strlen($truncatedDesc) > 100) {
            $truncatedDesc = substr($truncatedDesc, 0, 97) . '...';
        }
        
        return "- [{$this->name}]({$filename}) — {$truncatedDesc}";
    }
}