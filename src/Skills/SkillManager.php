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
     */
    public function loadFromDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        
        $files = glob($directory . '/*Skill.php');
        
        foreach ($files as $file) {
            $className = 'App\\SuperAgent\\Skills\\' . basename($file, '.php');
            
            if (class_exists($className)) {
                $skill = new $className();
                
                if ($skill instanceof Skill) {
                    $this->register($skill);
                }
            }
        }
    }

    /**
     * Reset the singleton instance (for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}