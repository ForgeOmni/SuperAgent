<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SUPERAGENT_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    */
    'default_model' => env('SUPERAGENT_MODEL', 'claude-sonnet-4-6-20250627'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    */
    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_version' => '2023-06-01',
            'max_tokens' => (int) env('SUPERAGENT_MAX_TOKENS', 8192),
            'max_retries' => 3,
        ],

        // Future: openai, bedrock, vertex, etc.
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Defaults
    |--------------------------------------------------------------------------
    */
    'agent' => [
        'max_turns' => (int) env('SUPERAGENT_MAX_TURNS', 50),
        'max_budget_usd' => (float) env('SUPERAGENT_MAX_BUDGET', 0),
        'working_directory' => env('SUPERAGENT_CWD', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Mode
    |--------------------------------------------------------------------------
    | Supported: "allowAll", "denyAll", "allowList"
    */
    'permission_mode' => env('SUPERAGENT_PERMISSION_MODE', 'allowAll'),

    'allowed_tools' => [
        // e.g. 'bash', 'read', 'write', 'edit', 'glob', 'grep'
    ],

    'denied_tools' => [],

    /*
    |--------------------------------------------------------------------------
    | Skills Configuration
    |--------------------------------------------------------------------------
    | Directories to auto-load skill files from. All paths are scanned
    | recursively. Supports absolute paths or paths relative to base_path().
    | Non-existent paths are silently skipped.
    */
    'skills' => [
        'paths' => [
            '.claude/skills',
            // app_path('SuperAgent/Skills'),
            // '/absolute/path/to/custom/skills',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents Configuration
    |--------------------------------------------------------------------------
    | Directories to auto-load agent definition files from. All paths are
    | scanned recursively. Supports absolute paths or paths relative to
    | base_path(). Non-existent paths are silently skipped.
    */
    'agents' => [
        'paths' => [
            '.claude/agents',
            // app_path('SuperAgent/Agents'),
            // '/absolute/path/to/custom/agents',
        ],
    ],

];
