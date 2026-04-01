<?php

namespace SuperAgent\Skills;

class SkillManager
{
    private static ?self $instance = null;
    
    private array $skills = [];
    private array $aliases = [];
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
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
     * Format: /skillname arg1=value1 arg2=value2
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
     */
    private function parseArguments(string $argString): array
    {
        $args = [];
        
        if (empty($argString)) {
            return $args;
        }
        
        // Simple parsing: key=value pairs
        preg_match_all('/(\w+)=([^\s]+|"[^"]*")/', $argString, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];
            
            // Remove quotes if present
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            
            $args[$key] = $value;
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
     * Load skills from all configured paths.
     * Default config value is ['.claude/skills'].
     */
    private function loadConfiguredPaths(): void
    {
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
    }

    /**
     * Load skills from a directory.
     *
     * Scans for *Skill.php files, parses their namespace from the source,
     * requires the file, and registers the Skill instance.
     * Works with any directory and namespace.
     */
    public function loadFromDirectory(string $directory, bool $recursive = false): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $pattern = $recursive
            ? $this->globRecursive($directory, '*Skill.php')
            : glob($directory . '/*Skill.php');

        foreach ($pattern as $file) {
            $className = $this->resolveClassNameFromFile($file);

            if ($className === null) {
                continue;
            }

            // Require the file if the class isn't already loaded
            if (!class_exists($className, false)) {
                require_once $file;
            }

            if (!class_exists($className, false)) {
                continue;
            }

            if (!is_subclass_of($className, Skill::class)) {
                continue;
            }

            $skill = new $className();
            $this->register($skill);
        }
    }

    /**
     * Load a single skill file from any path.
     */
    public function loadFromFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Skill file not found: {$filePath}");
        }

        $className = $this->resolveClassNameFromFile($filePath);

        if ($className === null) {
            throw new \RuntimeException("Could not resolve class name from file: {$filePath}");
        }

        if (!class_exists($className, false)) {
            require_once $filePath;
        }

        if (!class_exists($className, false)) {
            throw new \RuntimeException("Class {$className} not found after loading: {$filePath}");
        }

        if (!is_subclass_of($className, Skill::class)) {
            throw new \RuntimeException("Class {$className} is not a Skill subclass");
        }

        $this->register(new $className());
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