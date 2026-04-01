<?php

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SuperAgentMakeToolCommand extends Command
{
    protected $signature = 'superagent:make-tool
                            {name : The name of the tool}
                            {--category=custom : Tool category}
                            {--read-only : Make this a read-only tool}
                            {--force : Overwrite existing tool}';

    protected $description = 'Generate a new SuperAgent tool template';

    public function handle(): int
    {
        $name = $this->argument('name');
        $className = Str::studly($name) . 'Tool';
        $toolName = Str::snake($name);
        $category = $this->option('category');
        $readOnly = $this->option('read-only');
        
        $path = base_path("app/SuperAgent/Tools/{$className}.php");
        
        if (file_exists($path) && !$this->option('force')) {
            $this->error("Tool already exists: {$path}");
            $this->comment('Use --force to overwrite.');
            return 1;
        }
        
        // Create directory if it doesn't exist
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $stub = $this->getStub($className, $toolName, $category, $readOnly);
        file_put_contents($path, $stub);
        
        $this->info("✓ Tool created: {$path}");
        $this->line('');
        $this->comment('Next steps:');
        $this->line('  1. Implement the execute() method');
        $this->line('  2. Update the inputSchema() if needed');
        $this->line('  3. Register the tool in your service provider:');
        $this->line('');
        $this->info("     ToolRegistry::getInstance()->register(new \\App\\SuperAgent\\Tools\\{$className}());");
        $this->line('');
        
        return 0;
    }

    private function getStub(string $className, string $toolName, string $category, bool $readOnly): string
    {
        $readOnlyString = $readOnly ? 'true' : 'false';
        
        return <<<PHP
<?php

namespace App\SuperAgent\Tools;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

class {$className} extends Tool
{
    public function name(): string
    {
        return '{$toolName}';
    }

    public function description(): string
    {
        return 'Description of what this tool does';
    }

    public function category(): string
    {
        return '{$category}';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'example_param' => [
                    'type' => 'string',
                    'description' => 'An example parameter',
                ],
                'optional_param' => [
                    'type' => 'number',
                    'description' => 'An optional parameter',
                ],
            ],
            'required' => ['example_param'],
        ];
    }

    public function execute(array \$input): ToolResult
    {
        // Validate input
        if (!isset(\$input['example_param'])) {
            return ToolResult::error('example_param is required');
        }
        
        \$exampleParam = \$input['example_param'];
        \$optionalParam = \$input['optional_param'] ?? null;
        
        try {
            // TODO: Implement your tool logic here
            
            // Example: Process the input
            \$result = "Processed: {\$exampleParam}";
            
            if (\$optionalParam !== null) {
                \$result .= " with optional value: {\$optionalParam}";
            }
            
            return ToolResult::success(\$result);
            
        } catch (\\Exception \$e) {
            return ToolResult::error('Failed to execute tool: ' . \$e->getMessage());
        }
    }

    public function isReadOnly(): bool
    {
        return {$readOnlyString};
    }
}
PHP;
    }
}