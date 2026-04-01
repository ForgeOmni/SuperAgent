<?php

namespace SuperAgent\Skills;

abstract class Skill
{
    /**
     * Get the skill name (used as command).
     */
    abstract public function name(): string;

    /**
     * Get the skill description.
     */
    abstract public function description(): string;

    /**
     * Get the skill category.
     */
    public function category(): string
    {
        return 'general';
    }

    /**
     * Get the skill prompt template.
     * Can use placeholders like {{variable}}.
     */
    abstract public function template(): string;

    /**
     * Get the parameters this skill accepts.
     */
    public function parameters(): array
    {
        return [];
    }

    /**
     * Get required tools for this skill.
     */
    public function requiredTools(): array
    {
        return [];
    }

    /**
     * Execute the skill with given arguments.
     */
    public function execute(array $args = []): string
    {
        $template = $this->template();
        
        // Replace placeholders with arguments
        foreach ($args as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $template = str_replace($placeholder, $value, $template);
        }
        
        // Check for any remaining required placeholders
        if (preg_match_all('/{{(\w+)}}/', $template, $matches)) {
            $missing = array_diff($matches[1], array_keys($args));
            if (!empty($missing)) {
                throw new \InvalidArgumentException(
                    'Missing required parameters: ' . implode(', ', $missing)
                );
            }
        }
        
        return $template;
    }

    /**
     * Validate arguments against parameters.
     */
    public function validate(array $args): bool
    {
        $params = $this->parameters();
        
        foreach ($params as $param) {
            if ($param['required'] ?? false) {
                if (!isset($args[$param['name']])) {
                    return false;
                }
            }
            
            if (isset($args[$param['name']]) && isset($param['type'])) {
                $value = $args[$param['name']];
                
                switch ($param['type']) {
                    case 'string':
                        if (!is_string($value)) return false;
                        break;
                    case 'integer':
                        if (!is_int($value)) return false;
                        break;
                    case 'boolean':
                        if (!is_bool($value)) return false;
                        break;
                    case 'array':
                        if (!is_array($value)) return false;
                        break;
                }
            }
        }
        
        return true;
    }

    /**
     * Get example usage.
     */
    public function example(): string
    {
        return '';
    }
}