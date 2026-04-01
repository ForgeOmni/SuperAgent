<?php

namespace SuperAgent\Skills\BuiltinSkills;

use SuperAgent\Skills\Skill;

class TestSkill extends Skill
{
    public function name(): string
    {
        return 'test';
    }

    public function description(): string
    {
        return 'Generate tests for code';
    }

    public function category(): string
    {
        return 'development';
    }

    public function template(): string
    {
        return 'Please write comprehensive {{type}} tests for {{target}}.
{{#if coverage}}
Aim for {{coverage}}% code coverage.
{{/if}}
{{#if framework}}
Use the {{framework}} testing framework.
{{/if}}

Include tests for:
- Happy path scenarios
- Edge cases
- Error handling
- Input validation';
    }

    public function parameters(): array
    {
        return [
            [
                'name' => 'target',
                'type' => 'string',
                'required' => true,
                'description' => 'The file, class, or function to test',
            ],
            [
                'name' => 'type',
                'type' => 'string',
                'required' => false,
                'description' => 'Type of tests (unit, integration, feature)',
                'default' => 'unit',
            ],
            [
                'name' => 'coverage',
                'type' => 'integer',
                'required' => false,
                'description' => 'Target code coverage percentage',
            ],
            [
                'name' => 'framework',
                'type' => 'string',
                'required' => false,
                'description' => 'Testing framework to use',
            ],
        ];
    }

    public function requiredTools(): array
    {
        return ['read_file', 'create_file', 'bash'];
    }

    public function example(): string
    {
        return '/test target=UserService.php type=unit coverage=90 framework=PHPUnit';
    }
}