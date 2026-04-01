<?php

namespace SuperAgent\Skills\BuiltinSkills;

use SuperAgent\Skills\Skill;

class ReviewSkill extends Skill
{
    public function name(): string
    {
        return 'review';
    }

    public function description(): string
    {
        return 'Request a code review';
    }

    public function category(): string
    {
        return 'quality';
    }

    public function template(): string
    {
        return 'Please perform a {{type}} code review of {{target}}.

Focus on:
{{#if security}}
- Security vulnerabilities and best practices
{{/if}}
{{#if performance}}
- Performance issues and optimization opportunities
{{/if}}
{{#if standards}}
- Adherence to {{standards}} coding standards
{{/if}}
- Code quality and maintainability
- Potential bugs and edge cases
- Design patterns and architecture

Provide actionable feedback with severity levels (critical, major, minor, suggestion).';
    }

    public function parameters(): array
    {
        return [
            [
                'name' => 'target',
                'type' => 'string',
                'required' => true,
                'description' => 'The code to review',
            ],
            [
                'name' => 'type',
                'type' => 'string',
                'required' => false,
                'description' => 'Type of review (general, security, performance)',
                'default' => 'general',
            ],
            [
                'name' => 'security',
                'type' => 'boolean',
                'required' => false,
                'description' => 'Include security review',
            ],
            [
                'name' => 'performance',
                'type' => 'boolean',
                'required' => false,
                'description' => 'Include performance review',
            ],
            [
                'name' => 'standards',
                'type' => 'string',
                'required' => false,
                'description' => 'Coding standards to check against',
            ],
        ];
    }

    public function requiredTools(): array
    {
        return ['read_file'];
    }

    public function example(): string
    {
        return '/review target=UserController.php type=security standards=PSR-12';
    }
}