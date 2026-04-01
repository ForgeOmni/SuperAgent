<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

/**
 * An AgentDefinition loaded from a Markdown file.
 *
 * Frontmatter (YAML between ---) provides metadata,
 * the body is the full system prompt.
 * All frontmatter fields are preserved and accessible via getMeta().
 *
 * Supports $ARGUMENTS and $VARIABLE placeholders in the body,
 * replaced when resolving the system prompt with arguments.
 */
class MarkdownAgentDefinition extends AgentDefinition
{
    private array $frontmatter;
    private string $body;

    public function __construct(array $frontmatter, string $body)
    {
        if (empty($frontmatter['name'])) {
            throw new \RuntimeException('Agent markdown file missing "name" in frontmatter');
        }

        $this->frontmatter = $frontmatter;
        $this->body = trim($body);
    }

    public function name(): string
    {
        return $this->frontmatter['name'];
    }

    public function description(): string
    {
        return $this->frontmatter['description'] ?? '';
    }

    public function systemPrompt(): ?string
    {
        return $this->body !== '' ? $this->body : null;
    }

    public function allowedTools(): ?array
    {
        return $this->frontmatter['allowed_tools'] ?? null;
    }

    public function model(): ?string
    {
        $model = $this->frontmatter['model'] ?? null;
        return ($model === 'inherit' || $model === null) ? null : $model;
    }

    public function category(): string
    {
        return $this->frontmatter['category'] ?? 'general';
    }

    /**
     * Get any frontmatter field by key.
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->frontmatter[$key] ?? $default;
    }

    /**
     * Get all frontmatter metadata.
     */
    public function getAllMeta(): array
    {
        return $this->frontmatter;
    }
}
