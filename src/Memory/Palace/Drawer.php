<?php

declare(strict_types=1);

namespace SuperAgent\Memory\Palace;

use Carbon\Carbon;

/**
 * A Drawer holds the raw verbatim content — the exact words, never summarized.
 *
 * This is the core unit. MemPalace's 96.6% LongMemEval R@5 comes from
 * storing drawers raw and letting search find them, not from LLM extraction.
 *
 * A Drawer belongs to exactly one (wing, hall, room). Its contentHash is
 * used for duplicate detection.
 */
class Drawer
{
    public readonly Carbon $createdAt;
    public Carbon $updatedAt;
    public ?Carbon $accessedAt = null;
    public int $accessCount = 0;

    public function __construct(
        public readonly string $id,
        public readonly string $wingSlug,
        public readonly Hall $hall,
        public readonly string $roomSlug,
        public string $content,
        public string $contentHash = '',
        /** @var float[]|null Optional embedding vector */
        public ?array $embedding = null,
        public array $metadata = [],
        ?Carbon $createdAt = null,
        ?Carbon $updatedAt = null,
    ) {
        $this->createdAt = $createdAt ?? Carbon::now();
        $this->updatedAt = $updatedAt ?? Carbon::now();
        if ($this->contentHash === '') {
            $this->contentHash = self::hashContent($content);
        }
    }

    public static function hashContent(string $content): string
    {
        return substr(hash('sha256', trim($content)), 0, 16);
    }

    public static function generateId(): string
    {
        return 'drw_' . bin2hex(random_bytes(6));
    }

    public function markAccessed(): void
    {
        $this->accessedAt = Carbon::now();
        $this->accessCount++;
    }

    public function toMarkdown(): string
    {
        $yaml = "---\n";
        $yaml .= "id: {$this->id}\n";
        $yaml .= "wing: {$this->wingSlug}\n";
        $yaml .= "hall: {$this->hall->value}\n";
        $yaml .= "room: {$this->roomSlug}\n";
        $yaml .= "hash: {$this->contentHash}\n";
        $yaml .= "created_at: {$this->createdAt->toIso8601String()}\n";
        $yaml .= "updated_at: {$this->updatedAt->toIso8601String()}\n";
        if ($this->accessedAt) {
            $yaml .= "accessed_at: {$this->accessedAt->toIso8601String()}\n";
        }
        if ($this->accessCount > 0) {
            $yaml .= "access_count: {$this->accessCount}\n";
        }
        if ($this->embedding !== null) {
            $yaml .= "embedding_dim: " . count($this->embedding) . "\n";
        }
        foreach ($this->metadata as $key => $value) {
            if (is_scalar($value)) {
                $escaped = is_string($value) && (str_contains($value, ':') || str_contains($value, "\n"))
                    ? '"' . str_replace('"', '\\"', $value) . '"'
                    : (string) $value;
                $yaml .= "meta_{$key}: {$escaped}\n";
            }
        }
        $yaml .= "---\n\n";

        return $yaml . $this->content;
    }

    /**
     * Parse a drawer from markdown content + sidecar embedding.
     *
     * @param array<string,mixed> $frontmatter
     * @param float[]|null        $embedding
     */
    public static function fromMarkdown(array $frontmatter, string $content, ?array $embedding = null): self
    {
        $metadata = [];
        foreach ($frontmatter as $key => $value) {
            if (str_starts_with((string) $key, 'meta_')) {
                $metadata[substr((string) $key, 5)] = $value;
            }
        }

        $drawer = new self(
            id: $frontmatter['id'] ?? self::generateId(),
            wingSlug: $frontmatter['wing'] ?? 'wing_general',
            hall: Hall::from($frontmatter['hall'] ?? 'events'),
            roomSlug: $frontmatter['room'] ?? 'room',
            content: trim($content),
            contentHash: $frontmatter['hash'] ?? '',
            embedding: $embedding,
            metadata: $metadata,
            createdAt: isset($frontmatter['created_at']) ? Carbon::parse($frontmatter['created_at']) : null,
            updatedAt: isset($frontmatter['updated_at']) ? Carbon::parse($frontmatter['updated_at']) : null,
        );

        if (isset($frontmatter['accessed_at'])) {
            $drawer->accessedAt = Carbon::parse($frontmatter['accessed_at']);
        }
        if (isset($frontmatter['access_count'])) {
            $drawer->accessCount = (int) $frontmatter['access_count'];
        }

        return $drawer;
    }
}
