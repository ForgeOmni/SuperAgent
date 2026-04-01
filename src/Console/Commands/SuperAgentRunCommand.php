<?php

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Tools\ToolRegistry;

class SuperAgentRunCommand extends Command
{
    protected $signature = 'superagent:run
                            {prompt : The prompt to execute}
                            {--model=claude-3-haiku-20240307 : The model to use}
                            {--tools=* : Tools to enable (default: all)}
                            {--no-stream : Disable streaming output}
                            {--output= : Save output to file}
                            {--json : Output result as JSON}';

    protected $description = 'Execute a single prompt with SuperAgent';

    public function handle(): int
    {
        $prompt = $this->argument('prompt');
        
        if ($this->option('json')) {
            return $this->handleJson($prompt);
        }
        
        $this->info('🤖 SuperAgent Run');
        $this->line('');
        
        $agent = $this->createAgent();
        
        try {
            if ($agent->config->streaming && !$this->option('output')) {
                $this->info('Response:');
                $stream = $agent->stream($prompt);
                $fullResponse = '';
                
                foreach ($stream as $chunk) {
                    if (isset($chunk['content'])) {
                        $this->output->write($chunk['content']);
                        $fullResponse .= $chunk['content'];
                    }
                    
                    if (isset($chunk['tool_use'])) {
                        $this->line('');
                        $this->comment('🔧 Using tool: ' . $chunk['tool_use']['name']);
                    }
                    
                    if (isset($chunk['tool_result'])) {
                        $this->info('✓ Tool completed');
                    }
                }
                
                $this->line('');
                
                if ($outputFile = $this->option('output')) {
                    file_put_contents($outputFile, $fullResponse);
                    $this->info("Output saved to: {$outputFile}");
                }
            } else {
                $response = $agent->query($prompt);
                
                if ($outputFile = $this->option('output')) {
                    file_put_contents($outputFile, $response->content);
                    $this->info("Output saved to: {$outputFile}");
                } else {
                    $this->info('Response:');
                    $this->line($response->content);
                }
            }
            
            // Show cost information
            $cost = $agent->getCostTracker()->getTotalCost();
            if ($cost > 0) {
                $this->line('');
                $this->comment(sprintf('Cost: $%.6f', $cost));
            }
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }

    private function handleJson(string $prompt): int
    {
        $agent = $this->createAgent();
        
        try {
            $response = $agent->query($prompt);
            
            $output = [
                'prompt' => $prompt,
                'response' => $response->content,
                'model' => $this->option('model'),
                'tools_used' => $response->toolUses ?? [],
                'cost' => $agent->getCostTracker()->getTotalCost(),
                'timestamp' => now()->toIso8601String(),
            ];
            
            if ($outputFile = $this->option('output')) {
                file_put_contents($outputFile, json_encode($output, JSON_PRETTY_PRINT));
            } else {
                $this->line(json_encode($output, JSON_PRETTY_PRINT));
            }
            
        } catch (\Exception $e) {
            $error = [
                'error' => true,
                'message' => $e->getMessage(),
                'prompt' => $prompt,
                'timestamp' => now()->toIso8601String(),
            ];
            
            $this->line(json_encode($error, JSON_PRETTY_PRINT));
            return 1;
        }
        
        return 0;
    }

    private function createAgent(): Agent
    {
        $config = Config::fromArray([
            'provider' => [
                'type' => 'anthropic',
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => $this->option('model'),
            ],
            'tools' => $this->getEnabledTools(),
            'streaming' => !$this->option('no-stream'),
        ]);

        $provider = new AnthropicProvider($config->provider);
        return new Agent($provider, $config);
    }

    private function getEnabledTools(): array
    {
        $requestedTools = $this->option('tools');
        
        if (empty($requestedTools)) {
            return ToolRegistry::getInstance()->getAllTools();
        }

        $registry = ToolRegistry::getInstance();
        $tools = [];
        
        foreach ($requestedTools as $toolName) {
            $tool = $registry->getTool($toolName);
            if ($tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }
}