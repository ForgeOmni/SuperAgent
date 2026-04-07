<?php

namespace SuperAgent\Skills;

use SuperAgent\Support\MarkdownFrontmatter;

class SkillManager
{
    private static ?self $instance = null;
    
    private array $skills = [];
    private array $aliases = [];
    
    /**
     * @deprecated Use constructor injection instead.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->loadBuiltinSkills();
        $this->loadConfiguredPaths();
    }

    /**
     * Register a skill.
     */
    public function register(Skill $skill): void
    {
        $name = $skill->name();
        
        if (isset($this->skills[$name])) {
            throw new \RuntimeException("Skill already registered: {$name}");
        }
        
        $this->skills[$name] = $skill;
    }

    /**
     * Register an alias for a skill.
     */
    public function alias(string $alias, string $skillName): void
    {
        if (!isset($this->skills[$skillName])) {
            throw new \RuntimeException("Skill not found: {$skillName}");
        }
        
        $this->aliases[$alias] = $skillName;
    }

    /**
     * Get a skill by name or alias.
     */
    public function get(string $name): ?Skill
    {
        // Check if it's an alias
        if (isset($this->aliases[$name])) {
            $name = $this->aliases[$name];
        }
        
        return $this->skills[$name] ?? null;
    }

    /**
     * Execute a skill.
     */
    public function execute(string $name, array $args = []): string
    {
        $skill = $this->get($name);
        
        if (!$skill) {
            throw new \RuntimeException("Skill not found: {$name}");
        }
        
        if (!$skill->validate($args)) {
            throw new \InvalidArgumentException("Invalid arguments for skill: {$name}");
        }
        
        return $skill->execute($args);
    }

    /**
     * Parse and execute a skill command.
     *
     * Supports two formats:
     *   /skillname key=value key2=value2          — key=value pairs
     *   /skillname some free-form text here       — entire text becomes $ARGUMENTS
     *   /skillname free text key=value mixed      — both: free text as $ARGUMENTS + key=value pairs
     */
    public function parseAndExecute(string $command): ?string
    {
        if (!str_starts_with($command, '/')) {
            return null; // Not a skill command
        }

        // Parse command
        $parts = explode(' ', substr($command, 1), 2);
        $skillName = $parts[0];
        $argString = $parts[1] ?? '';

        $skill = $this->get($skillName);
        if (!$skill) {
            return null;
        }

        // Parse arguments
        $args = $this->parseArguments($argString);

        return $this->execute($skillName, $args);
    }

    /**
     * Parse argument string into array.
     *
     * Extracts key=value pairs into named args.
     * Everything that is NOT a key=value pair is collected
     * into the 'arguments' key (for $ARGUMENTS substitution).
     */
    private function parseArguments(string $argString): array
    {
        $args = [];

        if (empty(trim($argString))) {
            return $args;
        }

        // Extract key=value pairs
        $remaining = $argString;
        preg_match_all('/(\w+)=([^\s]+|"[^"]*")/', $argString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];

            // Remove quotes if present
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }

            $args[$key] = $value;

            // Remove the matched key=value from remaining text
            $remaining = str_replace($match[0], '', $remaining);
        }

        // The remaining text (non key=value parts) becomes $ARGUMENTS
        $remaining = trim(preg_replace('/\s+/', ' ', $remaining));
        if ($remaining !== '') {
            $args['arguments'] = $remaining;
        }

        return $args;
    }

    /**
     * Get all registered skills.
     */
    public function getAll(): array
    {
        return $this->skills;
    }

    /**
     * Get skills by category.
     */
    public function getByCategory(string $category): array
    {
        return array_filter(
            $this->skills,
            fn(Skill $skill) => $skill->category() === $category
        );
    }

    /**
     * Load skills from Claude Code directories and configured paths.
     */
    private function loadConfiguredPaths(): void
    {
        // Load from Claude Code directories if enabled
        if ($this->config('superagent.skills.load_claude_code', false)) {
            $this->loadFromDirectory($this->resolveBasePath('.claude/commands'), recursive: true);
            $this->loadFromDirectory($this->resolveBasePath('.claude/skills'), recursive: true);
        }

        // Load from additional configured paths
        $paths = $this->config('superagent.skills.paths', []);

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
        // Already absolute
        if (str_starts_with($path, '/') || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return $this->resolveBasePath($path);
    }

    /**
     * Get base path, using Laravel's base_path() if available,
     * otherwise walk up from cwd to find the project root.
     */
    private function resolveBasePath(string $relative): string
    {
        if ($this->isLaravelAvailable()) {
            return base_path($relative);
        }

        return self::findProjectRoot() . '/' . $relative;
    }

    /**
     * Find the project root by walking up from cwd looking for
     * composer.json, .git, or artisan. This prevents wrong resolution
     * when cwd is a subdirectory (e.g. docs/test/).
     */
    private static function findProjectRoot(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $dir = getcwd();
        for ($i = 0; $i < 20; $i++) {
            if (file_exists($dir . '/composer.json') || file_exists($dir . '/artisan') || is_dir($dir . '/.git')) {
                $cached = $dir;
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        $cached = getcwd();
        return $cached;
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
        } catch (\Throwable $e) {
            error_log('[SuperAgent] SkillManager config check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Load built-in skills.
     */
    private function loadBuiltinSkills(): void
    {
        // Register built-in skills here
        $this->register(new BuiltinSkills\RefactorSkill());
        $this->register(new BuiltinSkills\TestSkill());
        $this->register(new BuiltinSkills\DocumentSkill());
        $this->register(new BuiltinSkills\ReviewSkill());
        $this->register(new BuiltinSkills\DebugSkill());
        $this->register(new BuiltinSkills\BatchSkill());
    }

    /**
     * Load skills from a directory.
     * Supports both PHP (*Skill.php) and Markdown (*.md) files.
     */
    public function loadFromDirectory(string $directory, bool $recursive = false): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Load PHP files
        $phpFiles = $recursive
            ? $this->globRecursive($directory, '*Skill.php')
            : (glob($directory . '/*Skill.php') ?: []);

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
     * Load a single skill file (PHP or Markdown).
     */
    public function loadFromFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Skill file not found: {$filePath}");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        match ($ext) {
            'php' => $this->loadPhpFile($filePath, throw: true),
            'md' => $this->loadMarkdownFile($filePath, throw: true),
            default => throw new \RuntimeException("Unsupported skill file format: {$ext} ({$filePath})"),
        };
    }

    /**
     * Load a skill from a PHP file.
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

        if (!is_subclass_of($className, Skill::class)) {
            if ($throw) {
                throw new \RuntimeException("Class {$className} is not a Skill subclass");
            }
            return;
        }

        $this->register(new $className());
    }

    /**
     * Load a skill from a Markdown file.
     */
    private function loadMarkdownFile(string $file, bool $throw): void
    {
        try {
            $parsed = MarkdownFrontmatter::parseFile($file);
            $frontmatter = $parsed['frontmatter'];
            $body = $parsed['body'];

            if (empty($frontmatter['name'])) {
                if ($throw) {
                    throw new \RuntimeException("Markdown skill file missing 'name' in frontmatter: {$file}");
                }
                return;
            }

            $skill = new MarkdownSkill($frontmatter, $body);
            $this->register($skill);
        } catch (\RuntimeException $e) {
            if ($throw) {
                throw $e;
            }
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