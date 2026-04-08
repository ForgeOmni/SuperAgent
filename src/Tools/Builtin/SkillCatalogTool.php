<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Progressive skill disclosure with two-phase loading.
 *
 * Inspired by hermes-agent's skills system:
 *   Phase 1 (skills_list): Returns metadata only (name, description, tags)
 *   Phase 2 (skill_view): Loads full instructions + linked files on demand
 *
 * This reduces upfront token cost by not loading full skill content
 * until the model actually needs it.
 */
class SkillCatalogTool extends Tool
{
    /**
     * Skill directories to search.
     */
    private const SEARCH_PATHS = [
        './skills',
        './.skills',
        './src/skills',
        './resources/skills',
    ];

    /**
     * Required frontmatter fields for a valid SKILL.md.
     */
    private const REQUIRED_FIELDS = ['name', 'description'];

    public function name(): string
    {
        return 'skill_catalog';
    }

    public function description(): string
    {
        return 'Browse and load skills with progressive disclosure. Use "list" for metadata-only overview, "view" for full instructions.';
    }

    public function category(): string
    {
        return 'automation';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'view', 'search'],
                    'description' => 'Action: "list" (metadata only), "view" (full content), "search" (find by keyword).',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Skill name for view action.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query for search action.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Filter by tags.',
                ],
                'directory' => [
                    'type' => 'string',
                    'description' => 'Custom skill directory to search.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        return match ($input['action'] ?? '') {
            'list' => $this->listSkills($input),
            'view' => $this->viewSkill($input),
            'search' => $this->searchSkills($input),
            default => ToolResult::error("Invalid action: {$input['action']}. Use 'list', 'view', or 'search'."),
        };
    }

    /**
     * Phase 1: List skills with metadata only (no full content).
     * Reduces token overhead for initial skill discovery.
     */
    private function listSkills(array $input): ToolResult
    {
        $tags = $input['tags'] ?? [];
        $directory = $input['directory'] ?? null;

        $skills = $this->discoverSkills($directory);

        // Filter by tags
        if (!empty($tags)) {
            $skills = array_filter($skills, function ($skill) use ($tags) {
                $skillTags = $skill['tags'] ?? [];
                return !empty(array_intersect($tags, $skillTags));
            });
        }

        // Return metadata only — no full content
        $catalog = [];
        foreach ($skills as $skill) {
            $catalog[] = [
                'name' => $skill['name'],
                'description' => mb_substr($skill['description'] ?? '', 0, 120),
                'tags' => $skill['tags'] ?? [],
                'version' => $skill['version'] ?? null,
                'has_templates' => !empty($skill['templates']),
                'has_references' => !empty($skill['references']),
            ];
        }

        return ToolResult::success([
            'count' => count($catalog),
            'skills' => array_values($catalog),
            'hint' => 'Use action "view" with a skill name to load full instructions.',
        ]);
    }

    /**
     * Phase 2: Load full skill content on demand.
     * Only loads when the model actually needs the skill.
     */
    private function viewSkill(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        if (empty($name)) {
            return ToolResult::error('Skill name is required for view action.');
        }

        $directory = $input['directory'] ?? null;
        $skills = $this->discoverSkills($directory);

        foreach ($skills as $skill) {
            if (($skill['name'] ?? '') === $name) {
                return ToolResult::success([
                    'name' => $skill['name'],
                    'description' => $skill['description'] ?? '',
                    'version' => $skill['version'] ?? null,
                    'tags' => $skill['tags'] ?? [],
                    'instructions' => $skill['content'] ?? '',
                    'templates' => $skill['templates'] ?? [],
                    'references' => $skill['references'] ?? [],
                    'metadata' => $skill['metadata'] ?? [],
                ]);
            }
        }

        return ToolResult::error("Skill '{$name}' not found.");
    }

    /**
     * Search skills by keyword across names, descriptions, and content.
     */
    private function searchSkills(array $input): ToolResult
    {
        $query = strtolower($input['query'] ?? '');
        if (empty($query)) {
            return ToolResult::error('Search query is required.');
        }

        $directory = $input['directory'] ?? null;
        $skills = $this->discoverSkills($directory);

        $matches = [];
        foreach ($skills as $skill) {
            $searchText = strtolower(
                ($skill['name'] ?? '') . ' ' .
                ($skill['description'] ?? '') . ' ' .
                implode(' ', $skill['tags'] ?? [])
            );

            if (str_contains($searchText, $query)) {
                $matches[] = [
                    'name' => $skill['name'],
                    'description' => mb_substr($skill['description'] ?? '', 0, 120),
                    'tags' => $skill['tags'] ?? [],
                ];
            }
        }

        return ToolResult::success([
            'query' => $query,
            'count' => count($matches),
            'matches' => $matches,
        ]);
    }

    /**
     * Discover skill directories and parse SKILL.md frontmatter.
     */
    private function discoverSkills(?string $customDir = null): array
    {
        $searchPaths = $customDir ? [$customDir] : self::SEARCH_PATHS;
        $skills = [];

        foreach ($searchPaths as $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }

            // Each subdirectory is a skill
            $dirs = glob($basePath . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $skillDir) {
                $skillFile = $skillDir . '/SKILL.md';
                if (!file_exists($skillFile)) {
                    continue;
                }

                $skill = $this->parseSkillMd($skillFile, $skillDir);
                if ($skill !== null) {
                    $skills[] = $skill;
                }
            }

            // Also check for standalone .skill.md files
            $files = glob($basePath . '/*.skill.md') ?: [];
            foreach ($files as $file) {
                $skill = $this->parseSkillMd($file, dirname($file));
                if ($skill !== null) {
                    $skills[] = $skill;
                }
            }
        }

        return $skills;
    }

    /**
     * Parse a SKILL.md file with YAML frontmatter.
     */
    private function parseSkillMd(string $filePath, string $skillDir): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $parsed = $this->parseFrontmatter($content);
        $frontmatter = $parsed['frontmatter'];
        $body = $parsed['content'];

        // Validate required fields
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($frontmatter[$field])) {
                return null;
            }
        }

        // Discover linked files
        $templates = [];
        $templateDir = $skillDir . '/templates';
        if (is_dir($templateDir)) {
            $templates = array_map('basename', glob($templateDir . '/*') ?: []);
        }

        $references = [];
        $refDir = $skillDir . '/references';
        if (is_dir($refDir)) {
            $references = array_map('basename', glob($refDir . '/*') ?: []);
        }

        return [
            'name' => $frontmatter['name'],
            'description' => $frontmatter['description'] ?? '',
            'version' => $frontmatter['version'] ?? null,
            'tags' => $frontmatter['tags'] ?? $frontmatter['metadata']['hermes']['tags'] ?? [],
            'content' => $body,
            'templates' => $templates,
            'references' => $references,
            'metadata' => array_diff_key($frontmatter, array_flip(['name', 'description', 'version', 'tags'])),
            'path' => $skillDir,
        ];
    }

    /**
     * Parse YAML frontmatter from a markdown file.
     */
    private function parseFrontmatter(string $content): array
    {
        if (!str_starts_with(trim($content), '---')) {
            return ['frontmatter' => [], 'content' => $content];
        }

        $parts = preg_split('/^---\s*$/m', $content, 3);
        if (count($parts) < 3) {
            return ['frontmatter' => [], 'content' => $content];
        }

        $yamlStr = $parts[1];
        $body = trim($parts[2]);

        // Simple YAML parser for frontmatter
        $frontmatter = [];
        foreach (explode("\n", $yamlStr) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(\w+)\s*:\s*(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);

                // Handle arrays [a, b, c]
                if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                    $value = array_map('trim', explode(',', trim($value, '[]')));
                }

                $frontmatter[$key] = $value;
            }
        }

        return ['frontmatter' => $frontmatter, 'content' => $body];
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
