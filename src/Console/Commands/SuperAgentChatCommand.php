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

/**
 * Interactive chat command with a Claude Code-style real-time renderer.
 *
 * Every assistant turn streams thinking, tool invocations, and text deltas
 * through a RealTimeCliRenderer. A running turn / token / cost footer is
 * printed after each response.
 */
class SuperAgentChatCommand extends Command
{
    protected $signature = 'superagent:chat
                            {--model=claude-3-haiku-20240307 : The model to use}
                            {--tools=* : Tools to enable (default: all)}
                            {--no-stream : Disable streaming output}
                            {--verbose-thinking : Show full thinking text instead of a single-line preview}
                            {--no-thinking : Hide thinking entirely}
                            {--plain : Disable ANSI colors and cursor control (good for pipes / logs)}';

    protected $description = 'Start an interactive chat session with SuperAgent';

    private ?Agent $agent = null;
    private bool $running = true;

    public function handle(): int
    {
        $this->info('🤖 SuperAgent Chat');
        $this->info('Type "exit" or press Ctrl+C to quit');
        $this->info('Type "clear" to clear conversation history');
        $this->info('Type "tools" to list available tools');
        $this->line('');

        $this->initializeAgent();

        while ($this->running) {
            $input = $this->ask('You');

            if ($input === null || strtolower($input) === 'exit') {
                break;
            }

            if (strtolower($input) === 'clear') {
                $this->clearHistory();
                continue;
            }

            if (strtolower($input) === 'tools') {
                $this->listTools();
                continue;
            }

            $this->processQuery($input);
        }

        $this->info('Goodbye! 👋');
        return 0;
    }

    private function initializeAgent(): void
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
        $this->agent = new Agent($provider, $config);
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
            } else {
                $this->warn("Tool not found: {$toolName}");
            }
        }

        return $tools;
    }

    private function processQuery(string $input): void
    {
        $this->line('');

        try {
            if ($this->agent->config->streaming) {
                $this->runWithRenderer($input);
            } else {
                $response = $this->agent->query($input);
                $this->line($response->content);
            }
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }

        $this->line('');
    }

    private function runWithRenderer(string $input): void
    {
        $emitter = new StreamEventEmitter();
        $renderer = new RealTimeCliRenderer(
            output: $this->output,
            decorated: $this->option('plain') ? false : null,
            thinkingMode: $this->resolveThinkingMode(),
        );
        $renderer->attach($emitter);

        $handler = $emitter->toStreamingHandler();
        $result = $this->agent->prompt($input, $handler);

        $emitter->emit(new AgentCompleteEvent(
            totalTurns: count($result->allResponses ?? []),
            totalCostUsd: $result->totalCostUsd ?? 0.0,
            finalMessage: $result->message ?? null,
        ));
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

    private function clearHistory(): void
    {
        $this->initializeAgent();
        $this->info('✓ Conversation history cleared');
        $this->line('');
    }

    private function listTools(): void
    {
        $tools = BuiltinToolRegistry::all();

        $this->info('Available tools:');
        foreach ($tools as $tool) {
            $this->line("  • {$tool->name()} - {$tool->description()}");
        }
        $this->line('');
    }
}
