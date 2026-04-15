<?php

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use SuperAgent\Agent;
use SuperAgent\Config\Config;
use SuperAgent\Console\Output\RealTimeCliRenderer;
use SuperAgent\Harness\AgentCompleteEvent;
use SuperAgent\Harness\StreamEventEmitter;
use SuperAgent\Providers\AnthropicProvider;
use SuperAgent\Tools\BuiltinToolRegistry;

class SuperAgentRunCommand extends Command
{
    protected $signature = 'superagent:run
                            {prompt : The prompt to execute}
                            {--model=claude-3-haiku-20240307 : The model to use}
                            {--tools=* : Tools to enable (default: all)}
                            {--no-stream : Disable streaming output}
                            {--output= : Save output to file}
                            {--json : Output result as JSON}
                            {--verbose-thinking : Show full thinking text instead of a single-line preview}
                            {--no-thinking : Hide thinking entirely}
                            {--plain : Disable ANSI colors and cursor control}';

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
                $emitter = new StreamEventEmitter();
                $renderer = new RealTimeCliRenderer(
                    output: $this->output,
                    decorated: $this->option('plain') ? false : null,
                    thinkingMode: $this->resolveThinkingMode(),
                );
                $renderer->attach($emitter);

                $result = $agent->prompt($prompt, $emitter->toStreamingHandler());

                $emitter->emit(new AgentCompleteEvent(
                    totalTurns: count($result->allResponses ?? []),
                    totalCostUsd: $result->totalCostUsd ?? 0.0,
                    finalMessage: $result->message ?? null,
                ));

                if ($outputFile = $this->option('output')) {
                    file_put_contents($outputFile, (string) ($result->message->content ?? ''));
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

    private function resolveThinkingMode(): string
    {
        if ($this->option('no-thinking')) {
            return RealTimeCliRenderer::THINKING_HIDDEN;
        }
        if ($this->option('verbose-thinking')) {
            return RealTimeCliRenderer::THINKING_VERBOSE;
        }

        return RealTimeCliRenderer::THINKING_NORMAL;
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
        
        $allTools = BuiltinToolRegistry::all();

        if (empty($requestedTools)) {
            return array_values($allTools);
        }

        $tools = [];
        foreach ($requestedTools as $toolName) {
            if (isset($allTools[$toolName])) {
                $tools[] = $allTools[$toolName];
            }
        }

        return $tools;
    }
}