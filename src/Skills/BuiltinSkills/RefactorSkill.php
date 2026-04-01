<?php

namespace SuperAgent\Skills\BuiltinSkills;

use SuperAgent\Skills\Skill;

class RefactorSkill extends Skill
{
    public function name(): string
    {
        return 'refactor';
    }

    public function description(): string
    {
        return 'Request code refactoring with specific goals';
    }

    public function category(): string
    {
        return 'development';
    }

    public function template(): string
    {
        return 'Please refactor the code in {{file}} with the following goals:
- Improve {{aspect}}
{{#if pattern}}
- Follow the {{pattern}} pattern
{{/if}}
{{#if performance}}
- Optimize for performance, specifically: {{performance}}
{{/if}}

Ensure the refactored code maintains all existing functionality while improving code quality.';
    }

    public function parameters(): array
    {
        return [
            [
                'name' => 'file',
                'type' => 'string',
                'required' => true,
                'description' => 'The file or code section to refactor',
            ],
            [
                'name' => 'aspect',
                'type' => 'string',
                'required' => true,
                'description' => 'What aspect to improve (readability, maintainability, testability, etc.)',
            ],
            [
                'name' => 'pattern',
                'type' => 'string',
                'required' => false,
                'description' => 'Design pattern to apply',
            ],
            [
                'name' => 'performance',
                'type' => 'string',
                'required' => false,
                'description' => 'Performance optimization goals',
            ],
        ];
    }

    public function requiredTools(): array
    {
        return ['read_file', 'edit_file'];
    }

    public function example(): string
    {
        return '/refactor file=UserController.php aspect=readability pattern=Repository';
    }
}