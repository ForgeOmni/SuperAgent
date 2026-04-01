<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

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
     *
     * Scans for *Agent.php files, parses their namespace from the source,
     * requires the file, and registers the AgentDefinition instance.
     */
    public function loadFromDirectory(string $directory, bool $recursive = false): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $pattern = $recursive
            ? $this->globRecursive($directory, '*Agent.php')
            : glob($directory . '/*Agent.php');

        foreach ($pattern as $file) {
            $className = $this->resolveClassNameFromFile($file);

            if ($className === null) {
                continue;
            }

            if (!class_exists($className, false)) {
                require_once $file;
            }

            if (!class_exists($className, false)) {
                continue;
            }

            if (!is_subclass_of($className, AgentDefinition::class)) {
                continue;
            }

            $agent = new $className();
            $this->register($agent);
        }
    }

    /**
     * Load a single agent definition file from any path.
     */
    public function loadFromFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Agent file not found: {$filePath}");
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

        if (!is_subclass_of($className, AgentDefinition::class)) {
            throw new \RuntimeException("Class {$className} is not an AgentDefinition subclass");
        }

        $this->register(new $className());
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
     * Load agents from all configured paths.
     * Default config value is ['.claude/agents'].
     */
    private function loadConfiguredPaths(): void
    {
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
