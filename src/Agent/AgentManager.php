<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

use SuperAgent\Support\MarkdownFrontmatter;

/**
 * Registry and loader for agent definitions.
 */
class AgentManager
{
    private static ?self $instance = null;

    /** @var array<string, AgentDefinition> */
    private array $agents = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->loadBuiltinAgents();
        $this->loadConfiguredPaths();
    }

    /**
     * Register an agent definition.
     */
    public function register(AgentDefinition $agent): void
    {
        $name = $agent->name();

        if (isset($this->agents[$name])) {
            throw new \RuntimeException("Agent already registered: {$name}");
        }

        $this->agents[$name] = $agent;
    }

    /**
     * Get an agent definition by name.
     */
    public function get(string $name): ?AgentDefinition
    {
        return $this->agents[$name] ?? null;
    }

    /**
     * Check if an agent type is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->agents[$name]);
    }

    /**
     * Get all registered agent definitions.
     */
    public function getAll(): array
    {
        return $this->agents;
    }

    /**
     * Get all registered agent type names.
     */
    public function getNames(): array
    {
        return array_keys($this->agents);
    }

    /**
     * Get agents by category.
     */
    public function getByCategory(string $category): array
    {
        return array_filter(
            $this->agents,
            fn(AgentDefinition $agent) => $agent->category() === $category
        );
    }

    /**
     * Load agent definitions from a directory.
     * Supports both PHP (*Agent.php) and Markdown (*.md) files.
     */
    public function loadFromDirectory(string $directory, bool $recursive = false): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Load PHP files
        $phpFiles = $recursive
            ? $this->globRecursive($directory, '*Agent.php')
            : (glob($directory . '/*Agent.php') ?: []);

        foreach ($phpFiles as $file) {
            $this->loadPhpFile($file, throw: false);
        }

        // Load Markdown files
        $mdFiles = $recursive
            ? $this->globRecursive($directory, '*.md')
            : (glob($directory . '/*.md') ?: []);

        foreach ($mdFiles as $file) {
            $this->loadMarkdownFile($file, throw: false);
        }
    }

    /**
     * Load a single agent definition file (PHP or Markdown).
     */
    public function loadFromFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Agent file not found: {$filePath}");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        match ($ext) {
            'php' => $this->loadPhpFile($filePath, throw: true),
            'md' => $this->loadMarkdownFile($filePath, throw: true),
            default => throw new \RuntimeException("Unsupported agent file format: {$ext} ({$filePath})"),
        };
    }

    /**
     * Load an agent definition from a PHP file.
     */
    private function loadPhpFile(string $file, bool $throw): void
    {
        $className = $this->resolveClassNameFromFile($file);

        if ($className === null) {
            if ($throw) {
                throw new \RuntimeException("Could not resolve class name from file: {$file}");
            }
            return;
        }

        if (!class_exists($className, false)) {
            require_once $file;
        }

        if (!class_exists($className, false)) {
            if ($throw) {
                throw new \RuntimeException("Class {$className} not found after loading: {$file}");
            }
            return;
        }

        if (!is_subclass_of($className, AgentDefinition::class)) {
            if ($throw) {
                throw new \RuntimeException("Class {$className} is not an AgentDefinition subclass");
            }
            return;
        }

        $this->register(new $className());
    }

    /**
     * Load an agent definition from a Markdown file.
     */
    private function loadMarkdownFile(string $file, bool $throw): void
    {
        try {
            $parsed = MarkdownFrontmatter::parseFile($file);
            $frontmatter = $parsed['frontmatter'];
            $body = $parsed['body'];

            if (empty($frontmatter['name'])) {
                if ($throw) {
                    throw new \RuntimeException("Markdown agent file missing 'name' in frontmatter: {$file}");
                }
                return;
            }

            $agent = new MarkdownAgentDefinition($frontmatter, $body);
            $this->register($agent);
        } catch (\RuntimeException $e) {
            if ($throw) {
                throw $e;
            }
        }
    }

    /**
     * Load built-in agent types.
     */
    private function loadBuiltinAgents(): void
    {
        $this->register(new BuiltinAgents\GeneralPurposeAgent());
        $this->register(new BuiltinAgents\CodeWriterAgent());
        $this->register(new BuiltinAgents\ResearcherAgent());
        $this->register(new BuiltinAgents\ReviewerAgent());
    }

    /**
     * Load agents from Claude Code directory and configured paths.
     */
    private function loadConfiguredPaths(): void
    {
        // Load from Claude Code directory if enabled
        if ($this->config('superagent.agents.load_claude_code', false)) {
            $this->loadFromDirectory($this->resolveBasePath('.claude/agents'), recursive: true);
        }

        // Load from additional configured paths
        $paths = $this->config('superagent.agents.paths', []);

        foreach ($paths as $path) {
            $resolved = $this->resolvePath($path);
            $this->loadFromDirectory($resolved, recursive: true);
        }
    }

    /**
     * Resolve a path that may be relative to base_path().
     */
    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->resolveBasePath($path);
    }

    /**
     * Get base path, using Laravel's base_path() if available, otherwise cwd.
     */
    private function resolveBasePath(string $relative): string
    {
        if ($this->isLaravelAvailable()) {
            return base_path($relative);
        }

        return getcwd() . '/' . $relative;
    }

    /**
     * Read a config value, using Laravel's config() if available.
     */
    private function config(string $key, mixed $default = null): mixed
    {
        if ($this->isLaravelAvailable()) {
            return config($key, $default);
        }

        return $default;
    }

    /**
     * Check if Laravel is fully booted.
     */
    private function isLaravelAvailable(): bool
    {
        try {
            return function_exists('app')
                && app()->bound('config');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the fully qualified class name by parsing the PHP file.
     */
    private function resolveClassNameFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/^\s*namespace\s+([^;]+)\s*;/m', $contents, $m)) {
            $namespace = trim($m[1]);
        }

        if (preg_match('/^\s*class\s+(\w+)/m', $contents, $m)) {
            $class = $m[1];
        }

        if ($class === null) {
            return null;
        }

        return $namespace ? $namespace . '\\' . $class : $class;
    }

    /**
     * Recursively glob for files matching a pattern.
     */
    private function globRecursive(string $directory, string $pattern): array
    {
        $files = glob($directory . '/' . $pattern) ?: [];

        $dirs = glob($directory . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
        foreach ($dirs as $dir) {
            $files = array_merge($files, $this->globRecursive($dir, $pattern));
        }

        return $files;
    }

    /**
     * Reset the singleton instance (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
