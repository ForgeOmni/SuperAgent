<?php

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use SuperAgent\Tools\BuiltinToolRegistry;

class SuperAgentToolsCommand extends Command
{
    protected $signature = 'superagent:tools
                            {--category= : Filter by category}
                            {--search= : Search tools by name or description}
                            {--json : Output as JSON}';

    protected $description = 'List all available SuperAgent tools';

    public function handle(): int
    {
        $tools = BuiltinToolRegistry::all();
        
        // Apply filters
        if ($category = $this->option('category')) {
            $tools = array_filter($tools, fn($tool) => $tool->category() === $category);
        }
        
        if ($search = $this->option('search')) {
            $search = strtolower($search);
            $tools = array_filter($tools, function($tool) use ($search) {
                return str_contains(strtolower($tool->name()), $search) ||
                       str_contains(strtolower($tool->description()), $search);
            });
        }
        
        if ($this->option('json')) {
            $this->outputJson($tools);
        } else {
            $this->outputTable($tools);
        }
        
        return 0;
    }

    private function outputTable(array $tools): void
    {
        if (empty($tools)) {
            $this->warn('No tools found matching your criteria.');
            return;
        }
        
        $this->info('🔧 Available SuperAgent Tools');
        $this->line('');
        
        // Group by category
        $grouped = [];
        foreach ($tools as $tool) {
            $category = $tool->category();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $tool;
        }
        
        foreach ($grouped as $category => $categoryTools) {
            $this->comment(strtoupper($category) . ' TOOLS:');
            
            $rows = [];
            foreach ($categoryTools as $tool) {
                $rows[] = [
                    $tool->name(),
                    $tool->isReadOnly() ? '✓' : '✗',
                    $this->truncate($tool->description(), 50),
                ];
            }
            
            $this->table(
                ['Name', 'Read-Only', 'Description'],
                $rows
            );
        }
        
        $this->line('');
        $this->info('Total tools: ' . count($tools));
        
        // Show categories summary
        $categories = array_unique(array_map(fn($t) => $t->category(), $tools));
        $this->comment('Categories: ' . implode(', ', $categories));
    }

    private function outputJson(array $tools): void
    {
        $output = [];
        
        foreach ($tools as $tool) {
            $schema = $tool->inputSchema();
            $output[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'category' => $tool->category(),
                'read_only' => $tool->isReadOnly(),
                'input_schema' => $schema,
                'required_params' => $schema['required'] ?? [],
            ];
        }
        
        $this->line(json_encode([
            'tools' => $output,
            'count' => count($output),
        ], JSON_PRETTY_PRINT));
    }

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        
        return substr($text, 0, $length - 3) . '...';
    }
}