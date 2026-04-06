<?php

declare(strict_types=1);

namespace SuperAgent\Support;

/**
 * Parses Markdown files with YAML frontmatter.
 *
 * Format:
 * ---
 * key: value
 * list:
 *   - item1
 *   - item2
 * ---
 * Body content here...
 */
class MarkdownFrontmatter
{
    /**
     * Parse a markdown file into frontmatter array and body string.
     *
     * @return array{frontmatter: array, body: string}
     */
    public static function parse(string $content): array
    {
        $content = ltrim($content);

        if (!str_starts_with($content, '---')) {
            return ['frontmatter' => [], 'body' => $content];
        }

        // Find the closing ---
        $endPos = strpos($content, "\n---", 3);
        if ($endPos === false) {
            return ['frontmatter' => [], 'body' => $content];
        }

        $yamlString = substr($content, 3, $endPos - 3);
        $body = substr($content, $endPos + 4); // skip \n---

        $frontmatter = self::parseYaml($yamlString);

        return ['frontmatter' => $frontmatter, 'body' => ltrim($body, "\n")];
    }

    /**
     * Parse a file path.
     *
     * @return array{frontmatter: array, body: string}
     */
    public static function parseFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        return self::parse($content);
    }

    /**
     * Simple YAML parser for frontmatter.
     * Supports: scalars, quoted strings, and simple lists (- item).
     * Does not require ext-yaml.
     */
    private static function parseYaml(string $yaml): array
    {
        // Use ext-yaml if available
        if (function_exists('yaml_parse')) {
            $result = yaml_parse($yaml);
            return is_array($result) ? $result : [];
        }

        // Use symfony/yaml if available
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            try {
                $result = \Symfony\Component\Yaml\Yaml::parse($yaml);
                return is_array($result) ? $result : [];
            } catch (\Throwable $e) {
                error_log('[SuperAgent] Symfony YAML parse failed, using simple parser: ' . $e->getMessage());
            }
        }

        // Simple built-in parser
        return self::parseSimpleYaml($yaml);
    }

    /**
     * Minimal YAML parser covering frontmatter use cases.
     */
    private static function parseSimpleYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $currentList = null;

        foreach ($lines as $line) {
            // Skip empty lines and comments
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // List item (  - value)
            if (preg_match('/^\s+-\s+(.+)$/', $line, $m) && $currentKey !== null) {
                if ($currentList === null) {
                    $currentList = [];
                }
                $currentList[] = self::parseValue(trim($m[1]));
                $result[$currentKey] = $currentList;
                continue;
            }

            // Key: value pair
            if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $trimmed, $m)) {
                // Save previous list if any
                $currentKey = $m[1];
                $currentList = null;
                $value = trim($m[2]);

                if ($value === '') {
                    // Could be followed by a list
                    $result[$currentKey] = null;
                } else {
                    $result[$currentKey] = self::parseValue($value);
                }
            }
        }

        return $result;
    }

    /**
     * Parse a single YAML value.
     */
    private static function parseValue(string $value): string|int|float|bool|null
    {
        // Remove surrounding quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        // Boolean
        if (in_array(strtolower($value), ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array(strtolower($value), ['false', 'no', 'off'], true)) {
            return false;
        }

        // Null
        if (in_array(strtolower($value), ['null', '~'], true)) {
            return null;
        }

        // Integer
        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        // Float
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }
}
