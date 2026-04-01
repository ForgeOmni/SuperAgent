<?php

namespace SuperAgent\Skills\BuiltinSkills;

use SuperAgent\Skills\Skill;

class DocumentSkill extends Skill
{
    public function name(): string
    {
        return 'document';
    }

    public function description(): string
    {
        return 'Generate documentation for code';
    }

    public function category(): string
    {
        return 'documentation';
    }

    public function template(): string
    {
        return 'Please generate {{style}} documentation for {{target}}.

Documentation should include:
- Clear descriptions of purpose and functionality
- Parameter descriptions and types
- Return value descriptions
- Usage examples
{{#if format}}
- Output in {{format}} format
{{/if}}
{{#if audience}}
- Tailored for {{audience}} audience
{{/if}}';
    }

    public function parameters(): array
    {
        return [
            [
                'name' => 'target',
                'type' => 'string',
                'required' => true,
                'description' => 'The code to document',
            ],
            [
                'name' => 'style',
                'type' => 'string',
                'required' => false,
                'description' => 'Documentation style (inline, external, API)',
                'default' => 'inline',
            ],
            [
                'name' => 'format',
                'type' => 'string',
                'required' => false,
                'description' => 'Output format (markdown, html, docblock)',
            ],
            [
                'name' => 'audience',
                'type' => 'string',
                'required' => false,
                'description' => 'Target audience (developers, users, maintainers)',
            ],
        ];
    }

    public function requiredTools(): array
    {
        return ['read_file', 'edit_file', 'create_file'];
    }

    public function example(): string
    {
        return '/document target=Agent.php style=API format=markdown audience=developers';
    }
}