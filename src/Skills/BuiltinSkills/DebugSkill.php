<?php

namespace SuperAgent\Skills\BuiltinSkills;

use SuperAgent\Skills\Skill;

class DebugSkill extends Skill
{
    public function name(): string
    {
        return 'debug';
    }

    public function description(): string
    {
        return 'Help debug an issue';
    }

    public function category(): string
    {
        return 'troubleshooting';
    }

    public function template(): string
    {
        return 'Please help debug the following issue:

Error/Problem: {{issue}}
{{#if file}}
File: {{file}}
{{/if}}
{{#if stack}}
Stack trace:
{{stack}}
{{/if}}
{{#if context}}
Additional context: {{context}}
{{/if}}

Please:
1. Analyze the error and identify potential causes
2. Suggest specific debugging steps
3. Provide code fixes if applicable
4. Explain how to prevent this issue in the future';
    }

    public function parameters(): array
    {
        return [
            [
                'name' => 'issue',
                'type' => 'string',
                'required' => true,
                'description' => 'Description of the issue or error message',
            ],
            [
                'name' => 'file',
                'type' => 'string',
                'required' => false,
                'description' => 'File where the issue occurs',
            ],
            [
                'name' => 'stack',
                'type' => 'string',
                'required' => false,
                'description' => 'Stack trace or error log',
            ],
            [
                'name' => 'context',
                'type' => 'string',
                'required' => false,
                'description' => 'Additional context about when/how the issue occurs',
            ],
        ];
    }

    public function requiredTools(): array
    {
        return ['read_file', 'bash'];
    }

    public function example(): string
    {
        return '/debug issue="Undefined variable $user" file=UserController.php context="Happens when accessing /profile"';
    }
}