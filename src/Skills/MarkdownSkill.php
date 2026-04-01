<?php

declare(strict_types=1);

namespace SuperAgent\Skills;

/**
 * A Skill loaded from a Markdown file.
 *
 * Frontmatter (YAML between ---) provides metadata,
 * the body is the prompt template.
 *
 * Supports $ARGUMENTS placeholder (replaced with user input)
 * and {{variable}} / $VARIABLE placeholders (replaced via args).
 */
class MarkdownSkill extends Skill
{
    private array $frontmatter;
    private string $body;

    public function __construct(array $frontmatter, string $body)
    {
        if (empty($frontmatter['name'])) {
            throw new \RuntimeException('Skill markdown file missing "name" in frontmatter');
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

    public function template(): string
    {
        return $this->body;
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

    /**
     * Execute the skill.
     *
     * Returns the template as-is — all placeholders ($ARGUMENTS, $LANGUAGE, etc.)
     * are left for the LLM to interpret from the user's input context.
     * The caller is responsible for combining this template with the user's
     * prompt and sending both to the LLM.
     */
    public function execute(array $args = []): string
    {
        return $this->body;
    }
}
