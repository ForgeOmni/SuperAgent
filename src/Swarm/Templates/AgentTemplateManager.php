<?php

declare(strict_types=1);

namespace SuperAgent\Swarm\Templates;

use SuperAgent\Swarm\AgentSpawnConfig;
use SuperAgent\Agent\AgentDefinition;

/**
 * Manages agent templates for reusable configurations.
 * Allows defining common agent patterns and quickly spawning pre-configured agents.
 */
class AgentTemplateManager
{
    private static ?self $instance = null;
    private array $templates = [];
    private array $categories = [];
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->loadBuiltinTemplates();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        // Private constructor for singleton
    }
    
    /**
     * Register a new template.
     */
    public function register(AgentTemplate $template): void
    {
        $this->templates[$template->getName()] = $template;
        
        // Track categories
        if (!in_array($template->getCategory(), $this->categories)) {
            $this->categories[] = $template->getCategory();
        }
    }
    
    /**
     * Get a template by name.
     */
    public function get(string $name): ?AgentTemplate
    {
        return $this->templates[$name] ?? null;
    }
    
    /**
     * Get all templates in a category.
     */
    public function getByCategory(string $category): array
    {
        return array_filter(
            $this->templates,
            fn($template) => $template->getCategory() === $category
        );
    }
    
    /**
     * Create spawn config from template.
     */
    public function createSpawnConfig(
        string $templateName,
        array $variables = [],
        array $overrides = []
    ): AgentSpawnConfig {
        $template = $this->get($templateName);
        if (!$template) {
            throw new \InvalidArgumentException("Template not found: $templateName");
        }
        
        return $template->createSpawnConfig($variables, $overrides);
    }
    
    /**
     * Load built-in templates.
     */
    private function loadBuiltinTemplates(): void
    {
        // Data Processing Templates
        $this->register(new AgentTemplate(
            name: 'data_processor',
            category: 'data',
            description: 'Processes and transforms data',
            config: [
                'model' => 'claude-3-sonnet-20240229',
                'systemPrompt' => 'You are a data processing specialist. Process and transform data efficiently.',
                'allowedTools' => ['Read', 'Write', 'Bash', 'Grep'],
                'maxTokens' => 4096,
            ],
            variables: [
                'input_file' => 'Path to input data file',
                'output_format' => 'Desired output format (json, csv, xml)',
            ],
            promptTemplate: 'Process the data in {{input_file}} and convert it to {{output_format}} format.'
        ));
        
        $this->register(new AgentTemplate(
            name: 'etl_pipeline',
            category: 'data',
            description: 'Extract, Transform, Load pipeline agent',
            config: [
                'model' => 'claude-3-opus-20240229',
                'systemPrompt' => 'You are an ETL specialist. Extract data, transform it according to rules, and load to destination.',
                'allowedTools' => ['Read', 'Write', 'Edit', 'Bash', 'SQL'],
                'maxTokens' => 8192,
            ],
            variables: [
                'source' => 'Data source connection',
                'destination' => 'Destination system',
                'transformation_rules' => 'Transformation specifications',
            ],
            promptTemplate: 'Execute ETL pipeline: Extract from {{source}}, apply transformations: {{transformation_rules}}, load to {{destination}}.'
        ));
        
        // Code Analysis Templates
        $this->register(new AgentTemplate(
            name: 'code_reviewer',
            category: 'code',
            description: 'Reviews code for quality and best practices',
            config: [
                'model' => 'claude-3-opus-20240229',
                'systemPrompt' => 'You are an expert code reviewer. Analyze code for quality, security, performance, and best practices.',
                'allowedTools' => ['Read', 'Grep', 'Glob', 'Git'],
                'maxTokens' => 8192,
            ],
            variables: [
                'repository' => 'Repository path or URL',
                'focus_areas' => 'Specific areas to focus on',
                'standards' => 'Coding standards to apply',
            ],
            promptTemplate: 'Review the code in {{repository}}. Focus on: {{focus_areas}}. Apply standards: {{standards}}.'
        ));
        
        $this->register(new AgentTemplate(
            name: 'security_scanner',
            category: 'code',
            description: 'Scans code for security vulnerabilities',
            config: [
                'model' => 'claude-3-opus-20240229',
                'systemPrompt' => 'You are a security expert. Identify vulnerabilities, security issues, and potential attack vectors.',
                'allowedTools' => ['Read', 'Grep', 'Glob', 'Bash'],
                'maxTokens' => 8192,
            ],
            variables: [
                'target' => 'Target directory or files',
                'vulnerability_types' => 'Types of vulnerabilities to check',
            ],
            promptTemplate: 'Perform security scan on {{target}}. Check for: {{vulnerability_types}}.'
        ));
        
        // Research Templates
        $this->register(new AgentTemplate(
            name: 'web_researcher',
            category: 'research',
            description: 'Researches topics using web sources',
            config: [
                'model' => 'claude-3-sonnet-20240229',
                'systemPrompt' => 'You are a research specialist. Find, analyze, and synthesize information from various sources.',
                'allowedTools' => ['WebSearch', 'WebFetch', 'Write'],
                'maxTokens' => 4096,
            ],
            variables: [
                'topic' => 'Research topic',
                'depth' => 'Research depth (surface, moderate, deep)',
                'output_format' => 'Report format',
            ],
            promptTemplate: 'Research {{topic}} with {{depth}} analysis. Prepare report in {{output_format}} format.'
        ));
        
        $this->register(new AgentTemplate(
            name: 'documentation_writer',
            category: 'research',
            description: 'Creates comprehensive documentation',
            config: [
                'model' => 'claude-3-opus-20240229',
                'systemPrompt' => 'You are a technical documentation expert. Create clear, comprehensive, and well-structured documentation.',
                'allowedTools' => ['Read', 'Write', 'Grep', 'Glob'],
                'maxTokens' => 8192,
            ],
            variables: [
                'project' => 'Project to document',
                'audience' => 'Target audience',
                'sections' => 'Required sections',
            ],
            promptTemplate: 'Create documentation for {{project}} targeting {{audience}}. Include sections: {{sections}}.'
        ));
        
        // Testing Templates
        $this->register(new AgentTemplate(
            name: 'test_generator',
            category: 'testing',
            description: 'Generates comprehensive test cases',
            config: [
                'model' => 'claude-3-sonnet-20240229',
                'systemPrompt' => 'You are a testing expert. Generate comprehensive test cases, including edge cases and error scenarios.',
                'allowedTools' => ['Read', 'Write', 'Bash'],
                'maxTokens' => 4096,
            ],
            variables: [
                'code_path' => 'Path to code to test',
                'test_framework' => 'Testing framework to use',
                'coverage_target' => 'Target test coverage percentage',
            ],
            promptTemplate: 'Generate tests for {{code_path}} using {{test_framework}}. Aim for {{coverage_target}}% coverage.'
        ));
        
        $this->register(new AgentTemplate(
            name: 'performance_tester',
            category: 'testing',
            description: 'Runs performance tests and benchmarks',
            config: [
                'model' => 'claude-3-sonnet-20240229',
                'systemPrompt' => 'You are a performance testing specialist. Design and run performance tests, analyze results, identify bottlenecks.',
                'allowedTools' => ['Bash', 'Read', 'Write'],
                'maxTokens' => 4096,
            ],
            variables: [
                'target' => 'System or component to test',
                'load_profile' => 'Load testing profile',
                'metrics' => 'Metrics to collect',
            ],
            promptTemplate: 'Run performance test on {{target}} with load profile: {{load_profile}}. Collect metrics: {{metrics}}.'
        ));
        
        // Automation Templates
        $this->register(new AgentTemplate(
            name: 'ci_cd_agent',
            category: 'automation',
            description: 'Manages CI/CD pipeline tasks',
            config: [
                'model' => 'claude-3-sonnet-20240229',
                'systemPrompt' => 'You are a CI/CD specialist. Build, test, and deploy applications following best practices.',
                'allowedTools' => ['Bash', 'Git', 'Docker', 'Read', 'Write'],
                'maxTokens' => 4096,
            ],
            variables: [
                'pipeline_stage' => 'Current pipeline stage',
                'repository' => 'Repository to process',
                'environment' => 'Target environment',
            ],
            promptTemplate: 'Execute {{pipeline_stage}} for {{repository}} targeting {{environment}}.'
        ));
        
        $this->register(new AgentTemplate(
            name: 'deployment_agent',
            category: 'automation',
            description: 'Handles application deployments',
            config: [
                'model' => 'claude-3-sonnet-20240229',
                'systemPrompt' => 'You are a deployment specialist. Deploy applications safely with rollback capabilities.',
                'allowedTools' => ['Bash', 'SSH', 'Docker', 'Kubernetes'],
                'maxTokens' => 4096,
            ],
            variables: [
                'application' => 'Application to deploy',
                'version' => 'Version to deploy',
                'target_env' => 'Target environment',
                'strategy' => 'Deployment strategy',
            ],
            promptTemplate: 'Deploy {{application}} version {{version}} to {{target_env}} using {{strategy}} strategy.'
        ));
    }
    
    /**
     * Export templates to JSON.
     */
    public function export(): string
    {
        $export = [];
        foreach ($this->templates as $name => $template) {
            $export[$name] = $template->toArray();
        }
        return json_encode($export, JSON_PRETTY_PRINT);
    }
    
    /**
     * Import templates from JSON.
     */
    public function import(string $json): void
    {
        $data = json_decode($json, true);
        if (!$data) {
            throw new \InvalidArgumentException("Invalid JSON");
        }
        
        foreach ($data as $name => $templateData) {
            $template = AgentTemplate::fromArray($templateData);
            $this->register($template);
        }
    }
    
    /**
     * Get all categories.
     */
    public function getCategories(): array
    {
        return $this->categories;
    }
    
    /**
     * Get all templates.
     */
    public function getAllTemplates(): array
    {
        return $this->templates;
    }
}

/**
 * Represents an agent template.
 */
class AgentTemplate
{
    public function __construct(
        private string $name,
        private string $category,
        private string $description,
        private array $config,
        private array $variables = [],
        private ?string $promptTemplate = null,
        private array $metadata = []
    ) {}
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function getCategory(): string
    {
        return $this->category;
    }
    
    public function getDescription(): string
    {
        return $this->description;
    }
    
    /**
     * Create spawn config from template.
     */
    public function createSpawnConfig(array $variables = [], array $overrides = []): AgentSpawnConfig
    {
        // Merge config with overrides
        $config = array_merge($this->config, $overrides);
        
        // Process prompt template with variables
        $prompt = $this->processPromptTemplate($variables);
        
        return new AgentSpawnConfig(
            name: $config['name'] ?? $this->name . '_' . uniqid(),
            prompt: $prompt,
            model: $config['model'] ?? null,
            systemPrompt: $config['systemPrompt'] ?? null,
            allowedTools: $config['allowedTools'] ?? null,
            maxTokens: $config['maxTokens'] ?? null,
            temperature: $config['temperature'] ?? null,
            metadata: array_merge($this->metadata, [
                'template' => $this->name,
                'category' => $this->category,
            ])
        );
    }
    
    /**
     * Process prompt template with variables.
     */
    private function processPromptTemplate(array $variables): string
    {
        if (!$this->promptTemplate) {
            return '';
        }
        
        $prompt = $this->promptTemplate;
        
        // Replace variables in template
        foreach ($variables as $key => $value) {
            $prompt = str_replace("{{$key}}", $value, $prompt);
        }
        
        // Check for missing variables
        if (preg_match('/\{\{(\w+)\}\}/', $prompt, $matches)) {
            throw new \InvalidArgumentException("Missing variable: {$matches[1]}");
        }
        
        return $prompt;
    }
    
    /**
     * Convert to array for export.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'config' => $this->config,
            'variables' => $this->variables,
            'prompt_template' => $this->promptTemplate,
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            category: $data['category'],
            description: $data['description'],
            config: $data['config'],
            variables: $data['variables'] ?? [],
            promptTemplate: $data['prompt_template'] ?? null,
            metadata: $data['metadata'] ?? []
        );
    }
}