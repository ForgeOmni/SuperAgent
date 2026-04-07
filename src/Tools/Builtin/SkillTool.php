<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class SkillTool extends Tool
{
    public function name(): string
    {
        return 'skill';
    }

    public function description(): string
    {
        return 'Manage and execute reusable skills (prompt templates and tool combinations).';
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
                    'enum' => ['register', 'execute', 'list', 'get', 'remove'],
                    'description' => 'Skill action: register, execute, list, get, or remove.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Skill name.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Skill description.',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Prompt template for the skill.',
                ],
                'tools' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Required tools for this skill.',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Parameters to pass when executing skill.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Tags for categorizing the skill.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'register':
                return $this->registerSkill($input);
            case 'execute':
                return $this->executeSkill($input);
            case 'list':
                return $this->listSkills($input);
            case 'get':
                return $this->getSkill($input);
            case 'remove':
                return $this->removeSkill($input);
            default:
                return ToolResult::error("Invalid action: {$action}");
        }
    }

    private function registerSkill(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $prompt = $input['prompt'] ?? '';
        $tools = $input['tools'] ?? [];
        $tags = $input['tags'] ?? [];

        if (empty($name)) {
            return ToolResult::error('Skill name is required.');
        }

        if (empty($prompt)) {
            return ToolResult::error('Skill prompt is required.');
        }

        $skills = $this->state()->get($this->name(), 'skills', []);

        if (isset($skills[$name])) {
            return ToolResult::error("Skill '{$name}' already exists.");
        }

        $this->state()->putIn($this->name(), 'skills', $name, [
            'name' => $name,
            'description' => $description,
            'prompt' => $prompt,
            'tools' => $tools,
            'tags' => $tags,
            'created_at' => date('Y-m-d H:i:s'),
            'execution_count' => 0,
            'last_executed' => null,
        ]);

        return ToolResult::success([
            'message' => 'Skill registered successfully',
            'name' => $name,
            'tools_required' => count($tools),
        ]);
    }

    private function executeSkill(array $input): ToolResult
    {
        $name = $input['name'] ?? '';
        $parameters = $input['parameters'] ?? [];

        if (empty($name)) {
            return ToolResult::error('Skill name is required.');
        }

        $skills = $this->state()->get($this->name(), 'skills', []);

        if (!isset($skills[$name])) {
            return ToolResult::error("Skill '{$name}' not found.");
        }

        $skill = $skills[$name];

        // Replace parameters in prompt
        $prompt = $skill['prompt'];
        foreach ($parameters as $key => $value) {
            $prompt = str_replace("{{{$key}}}", $value, $prompt);
        }

        // Update execution stats
        $skill['execution_count']++;
        $skill['last_executed'] = date('Y-m-d H:i:s');

        $this->state()->putIn($this->name(), 'skills', $name, $skill);

        return ToolResult::success([
            'message' => 'Skill executed',
            'name' => $name,
            'expanded_prompt' => $prompt,
            'tools_required' => $skill['tools'],
            'execution_count' => $skill['execution_count'],
        ]);
    }

    private function listSkills(array $input): ToolResult
    {
        $tags = $input['tags'] ?? [];
        
        $filtered = $this->state()->get($this->name(), 'skills', []);
        
        if (!empty($tags)) {
            $filtered = array_filter($filtered, function($skill) use ($tags) {
                return !empty(array_intersect($tags, $skill['tags']));
            });
        }

        $summary = [];
        foreach ($filtered as $skill) {
            $summary[] = [
                'name' => $skill['name'],
                'description' => $skill['description'],
                'tools' => count($skill['tools']),
                'tags' => $skill['tags'],
                'execution_count' => $skill['execution_count'],
            ];
        }

        return ToolResult::success([
            'count' => count($summary),
            'skills' => $summary,
        ]);
    }

    private function getSkill(array $input): ToolResult
    {
        $name = $input['name'] ?? '';

        if (empty($name)) {
            return ToolResult::error('Skill name is required.');
        }

        $skills = $this->state()->get($this->name(), 'skills', []);

        if (!isset($skills[$name])) {
            return ToolResult::error("Skill '{$name}' not found.");
        }

        return ToolResult::success($skills[$name]);
    }

    private function removeSkill(array $input): ToolResult
    {
        $name = $input['name'] ?? '';

        if (empty($name)) {
            return ToolResult::error('Skill name is required.');
        }

        $skills = $this->state()->get($this->name(), 'skills', []);

        if (!isset($skills[$name])) {
            return ToolResult::error("Skill '{$name}' not found.");
        }

        $this->state()->removeFrom($this->name(), 'skills', $name);

        return ToolResult::success([
            'message' => 'Skill removed successfully',
            'name' => $name,
        ]);
    }

    public function clearSkills(): void
    {
        $this->state()->clearTool($this->name());
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}