<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\AgentFactory;
use SuperAgent\CLI\Terminal\Renderer;

/**
 * Interactive chat / one-shot prompt command.
 *
 * This is the default command when running `superagent`.
 * Without a prompt argument, it starts an interactive REPL.
 * With a prompt, it runs a single task and exits.
 */
class ChatCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $factory = new AgentFactory($renderer);

        try {
            $agent = $factory->createAgent($options);
        } catch (\Throwable $e) {
            $renderer->error("Failed to create agent: {$e->getMessage()}");
            $renderer->hint("Run 'superagent init' to configure your API key.");
            return 1;
        }

        // One-shot mode: prompt provided as argument
        if (! empty($options['prompt'])) {
            return $this->runOneShot($factory, $agent, $options, $renderer);
        }

        // Interactive REPL mode
        return $this->runInteractive($factory, $agent, $options, $renderer);
    }

    private function runOneShot(AgentFactory $factory, $agent, array $options, Renderer $renderer): int
    {
        $prompt = $options['prompt'];
        $outputMode = (string) ($options['output'] ?? '');
        $jsonStream = $outputMode === 'json-stream';
        $rich = ($options['rich'] ?? true) && ! ($options['json'] ?? false) && ! $jsonStream;

        if (! $rich && ! $jsonStream) {
            $renderer->info("Running: {$prompt}");
            $renderer->separator();
        }

        try {
            if ($jsonStream) {
                // One line of JSON per wire event to stdout — no summary
                // line, no prompt echo. IDE bridges / CI pipelines
                // consume the stream directly with `jq -c` / similar.
                $emitter = $factory->makeJsonStreamEmitter();
                $factory->runOneShot($agent, $prompt, $emitter);
                return 0;
            }

            if ($rich) {
                // Rich renderer prints thinking, tool activity, streaming text,
                // and a footer with turn / tokens / cost on its own.
                $emitter = $factory->makeRichEmitter($options);
                $result = $factory->runOneShot($agent, $prompt, $emitter);

                return 0;
            }

            $result = $factory->runOneShot($agent, $prompt);

            if ($options['json'] ?? false) {
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            } else {
                $renderer->assistantMessage($result['content']);
                $renderer->separator();
                $renderer->cost($result['cost'], $result['turns']);
            }

            return 0;
        } catch (\Throwable $e) {
            if ($jsonStream) {
                // Error envelope in wire v1 shape so consumers don't
                // have to special-case stderr.
                fwrite(
                    STDOUT,
                    json_encode([
                        'wire_version' => 1,
                        'type' => 'error',
                        'timestamp' => microtime(true),
                        'message' => $e->getMessage(),
                        'recoverable' => false,
                        'code' => null,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
                );
                return 1;
            }
            $renderer->error($e->getMessage());
            return 1;
        }
    }

    private function runInteractive(AgentFactory $factory, $agent, array $options, Renderer $renderer): int
    {
        $renderer->banner();

        try {
            $loop = $factory->createHarnessLoop($agent, $options);
        } catch (\Throwable $e) {
            $renderer->error("Failed to initialize: {$e->getMessage()}");
            return 1;
        }

        // Wire terminal input/output to HarnessLoop
        $inputProvider = function () use ($renderer): ?string {
            return $renderer->prompt();
        };

        $outputHandler = function (string $text) use ($renderer): void {
            $renderer->assistantMessage($text);
        };

        try {
            $loop->run($inputProvider, $outputHandler);
        } catch (\Throwable $e) {
            $renderer->error("Session error: {$e->getMessage()}");
            return 1;
        }

        $renderer->info('Goodbye!');
        return 0;
    }
}
