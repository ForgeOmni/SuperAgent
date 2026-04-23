<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

use SuperAgent\Support\MarkdownFrontmatter;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML agent spec loader with `extend:` inheritance.
 *
 * Conceptually:
 *   parent.yaml:
 *     name: base-coder
 *     system_prompt: "You are a careful coder."
 *     allowed_tools: [Read, Write, Edit, Bash]
 *
 *   child.yaml:
 *     extend: base-coder
 *     name: reviewer
 *     description: "Reviews but never writes."
 *     read_only: true
 *     exclude_tools: [Write, Edit]     # merged into parent's disallowed list
 *
 * The resolved child spec inherits `system_prompt` and `allowed_tools`
 * from the parent, overrides `description` and `read_only`, and its
 * `exclude_tools` accumulates with the parent's. The final spec is
 * then handed to `YamlAgentDefinition` which treats it like any other
 * spec — inheritance is fully resolved at load time so the runtime
 * never sees the `extend` key.
 *
 * Resolution search order for `extend: <name>` (first hit wins):
 *   1. Other specs loaded from the same directory as the child.
 *   2. `$extraSearchDirs` passed at construction (the AgentManager
 *      feeds in its built-in + project + user agent dirs).
 *   3. `<name>.yaml` / `<name>.yml` in each dir.
 *
 * No cycles: a depth counter caps recursion at 10 to catch `A→B→A`
 * loops without a full visited-set.
 */
class AgentSpecLoader
{
    /** @var list<string> */
    private array $searchDirs;

    /**
     * @param list<string> $searchDirs Extra directories to search for
     *                                 `extend:` parents (in addition to
     *                                 the child's own directory).
     */
    public function __construct(array $searchDirs = [])
    {
        $this->searchDirs = array_values(array_filter(
            $searchDirs,
            static fn ($d) => is_string($d) && is_dir($d),
        ));
    }

    /**
     * Parse a YAML file, resolve its `extend:` chain, return a
     * `YamlAgentDefinition` ready to register.
     */
    public function loadFile(string $path): YamlAgentDefinition
    {
        $spec = $this->parseAndResolve($path, depth: 0);
        // Resolve `system_prompt_path` relative to the child's file if
        // the final spec still references one (parent's system_prompt
        // wins over parent's system_prompt_path when both are set).
        $this->resolvePromptPath($spec, dirname($path));
        return new YamlAgentDefinition($spec);
    }

    /**
     * Resolve `extend:` inheritance for an arbitrary spec map. Used by
     * `AgentManager::loadMarkdownFile()` to share the same inheritance
     * semantics between YAML and Markdown agents: a markdown file's
     * frontmatter becomes the child spec, and the body is carried on
     * `system_prompt` before resolution (so a child whose body is empty
     * transparently inherits the parent's body).
     *
     * @param array<string, mixed> $spec  The child spec (YAML or frontmatter).
     * @param string $ownerDir            Directory of the file this spec came
     *                                    from — used to resolve relative
     *                                    `extend:` lookups first before
     *                                    consulting `$searchDirs`.
     * @return array<string, mixed> Fully-resolved spec (no `extend` key).
     */
    public function resolveSpec(array $spec, string $ownerDir, int $depth = 0): array
    {
        if ($depth > 10) {
            throw new \RuntimeException(
                "Agent spec extend: chain exceeds depth 10 — cycle? ({$ownerDir})"
            );
        }
        if (empty($spec['extend'])) {
            unset($spec['extend']);
            return $spec;
        }

        $parentName = (string) $spec['extend'];
        $parentPath = $this->findParentFile($parentName, $ownerDir);
        if ($parentPath === null) {
            throw new \RuntimeException(
                "Agent spec `extend: {$parentName}` referenced but no parent file found"
            );
        }

        $parentSpec = $this->parseSpecFile($parentPath);
        $parentSpec = $this->resolveSpec($parentSpec, dirname($parentPath), $depth + 1);

        return $this->mergeSpec($parentSpec, $spec);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAndResolve(string $path, int $depth): array
    {
        if ($depth > 10) {
            throw new \RuntimeException(
                "Agent spec extend: chain exceeds depth 10 — cycle? ({$path})"
            );
        }
        if (!is_readable($path)) {
            throw new \RuntimeException("YAML agent file not readable: {$path}");
        }
        $spec = Yaml::parseFile($path);
        if (!is_array($spec)) {
            throw new \RuntimeException("YAML agent file is not a map: {$path}");
        }

        return $this->resolveSpec($spec, dirname($path), $depth);
    }

    /**
     * Load any supported spec file format into a plain array — YAML,
     * YML, or Markdown (frontmatter becomes the spec, body lands on
     * `system_prompt` if non-empty). Extensions outside this set throw.
     *
     * @return array<string, mixed>
     */
    private function parseSpecFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Agent spec file not readable: {$path}");
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'yaml', 'yml' => $this->parseYamlFile($path),
            'md'          => $this->parseMarkdownFile($path),
            default       => throw new \RuntimeException("Unsupported agent spec extension: .{$ext} ({$path})"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYamlFile(string $path): array
    {
        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
            throw new \RuntimeException("YAML agent file is not a map: {$path}");
        }
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMarkdownFile(string $path): array
    {
        $parsed = MarkdownFrontmatter::parseFile($path);
        $fm = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
        $body = trim((string) ($parsed['body'] ?? ''));
        // Promote the markdown body onto `system_prompt` so tree-wide
        // merge semantics work uniformly: a child with no body (common
        // for extending markdown templates) inherits the parent's body.
        if ($body !== '' && !isset($fm['system_prompt'])) {
            $fm['system_prompt'] = $body;
        }
        return $fm;
    }

    /**
     * Merge parent + child specs. Child overrides parent at the top
     * level; list-valued tool keys concatenate (`allowed_tools`,
     * `disallowed_tools`, `exclude_tools`) so extending an agent to
     * add permissions is ergonomic — you don't have to repeat the
     * parent's list.
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     * @return array<string, mixed>
     */
    private function mergeSpec(array $parent, array $child): array
    {
        $merged = $parent;
        foreach ($child as $k => $v) {
            if ($k === 'extend') {
                continue;   // consumed
            }
            if (in_array($k, ['allowed_tools', 'disallowed_tools', 'exclude_tools'], true)
                && isset($merged[$k]) && is_array($merged[$k]) && is_array($v)
            ) {
                $merged[$k] = array_values(array_unique(array_merge($merged[$k], $v)));
                continue;
            }
            $merged[$k] = $v;
        }
        return $merged;
    }

    /**
     * Locate a parent file by name, searching YAML / YML / MD across
     * the child's own directory first, then each registered search
     * directory. First hit wins — `base.yaml` and `base.md` in the
     * same dir would resolve to the YAML one; callers should keep
     * agent names unique across formats to avoid surprise.
     */
    private function findParentFile(string $name, string $childDir): ?string
    {
        $dirs = array_merge([$childDir], $this->searchDirs);
        foreach ($dirs as $dir) {
            foreach (['.yaml', '.yml', '.md'] as $ext) {
                $candidate = rtrim($dir, '/\\') . '/' . $name . $ext;
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * If the resolved spec has `system_prompt_path` but no `system_prompt`,
     * read the file (relative to the child's directory) into `system_prompt`.
     *
     * @param array<string, mixed> $spec
     */
    private function resolvePromptPath(array &$spec, string $childDir): void
    {
        if (!empty($spec['system_prompt'])) {
            return;
        }
        if (empty($spec['system_prompt_path']) || !is_string($spec['system_prompt_path'])) {
            return;
        }
        $promptPath = $spec['system_prompt_path'];
        if ($promptPath[0] !== '/') {
            $promptPath = rtrim($childDir, '/\\') . '/' . $promptPath;
        }
        if (is_readable($promptPath)) {
            $spec['system_prompt'] = (string) file_get_contents($promptPath);
        }
    }
}
