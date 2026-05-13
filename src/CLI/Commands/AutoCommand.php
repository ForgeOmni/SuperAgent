<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\AutoMode\AutoModeAgent;
use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Config\ConfigRepository;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * `superagent auto` — heuristic-driven auto-mode entry.
 *
 * Wraps the existing `SuperAgent\AutoMode\AutoModeAgent`, which uses keyword
 * + length + structure heuristics to decide whether to run as a single agent
 * or fan out into a multi-agent execution (parsing the prompt into subtasks
 * by enumeration / bullets / aspect keywords).
 *
 * Distinct from `superagent smart`: smart reads eval scores from
 * `~/.superagent/model_scores.json` and uses a brain model to plan; auto is
 * purely rule-based and runs the same model on every subtask.
 *
 * Usage:
 *   superagent auto "<task>"                       run with defaults
 *   superagent auto "<task>" --model <m>           override the model
 *   superagent auto "<task>" --provider <p>        override provider
 *   superagent auto "<task>" --analyze-only        show the analysis, don't run
 *   superagent auto "<task>" --verbose             print analysis + multi-agent display
 */
final class AutoCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['auto_args'] ?? [];

        [$task, $opts] = $this->parseArgs($args);
        if ($task === '') {
            return $this->usage($renderer);
        }

        $cfg = ConfigRepository::getInstance();
        $provider = $opts['provider']
            ?? $options['provider']
            ?? $cfg->get('superagent.default_provider', 'anthropic');
        $model = $opts['model']
            ?? $options['model']
            ?? $cfg->get('superagent.default_model', 'claude-sonnet-4-6-20250627');

        $verbose = (bool) ($options['verbose'] ?? false) || ($opts['verbose'] ?? false);

        // Symfony Console output is what AutoModeAgent's analyzer + parallel
        // display expect. Verbose mode unlocks the analysis printouts.
        $consoleOut = new ConsoleOutput(
            $verbose ? ConsoleOutput::VERBOSITY_VERBOSE : ConsoleOutput::VERBOSITY_NORMAL,
        );

        $autoConfig = $cfg->get('superagent.auto_mode', []);
        $autoConfig = is_array($autoConfig) ? $autoConfig : [];

        $autoModeAgent = new AutoModeAgent(
            config: [
                'auto_mode'         => true,
                'provider'          => $provider,
                'model'             => $model,
                'analyzer_config'   => $autoConfig['threshold'] ?? [],
                'multi_agent_config'=> [
                    'max_agents'       => (int) ($autoConfig['multi_agent']['max_agents'] ?? 10),
                    'backend'          => 'in_process',
                    'enable_display'   => $verbose,
                    'refresh_interval' => 500,
                ],
            ],
            logger: null,
            output: $consoleOut,
        );

        if ($opts['analyze_only'] ?? false) {
            $analyzer = new \SuperAgent\AutoMode\TaskAnalyzer($autoConfig['threshold'] ?? []);
            $analysis = $analyzer->analyzeTask($task);
            $renderer->info('Auto-mode analysis:');
            $renderer->line('  ' . (string) $analysis);
            foreach ($analysis->getMetrics() as $k => $v) {
                $renderer->line(sprintf('    %-20s %s', $k, is_numeric($v) ? number_format((float) $v, 2) : (string) $v));
            }
            return 0;
        }

        $renderer->info(sprintf('Auto mode · provider=%s · model=%s', $provider, $model));
        $renderer->separator();

        try {
            $result = $autoModeAgent->run($task);
        } catch (\Throwable $e) {
            $renderer->error('Auto run failed: ' . $e->getMessage());
            return 1;
        }

        $renderer->newLine();
        $renderer->separator();
        $renderer->info('Result:');
        $renderer->separator();
        $renderer->assistantMessage($result->text());
        $renderer->newLine();
        $renderer->separator();
        $renderer->cost((float) $result->totalCostUsd, $result->turns());
        return 0;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseArgs(array $args): array
    {
        $opts = [
            'model'        => null,
            'provider'     => null,
            'analyze_only' => false,
            'verbose'      => false,
        ];
        $promptParts = [];
        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            if ($a === '--model' || $a === '-m') {
                $opts['model'] = (string) ($args[++$i] ?? '');
            } elseif ($a === '--provider' || $a === '-p') {
                $opts['provider'] = (string) ($args[++$i] ?? '');
            } elseif ($a === '--analyze-only') {
                $opts['analyze_only'] = true;
            } elseif ($a === '--verbose' || $a === '-v') {
                $opts['verbose'] = true;
            } elseif (! str_starts_with($a, '-')) {
                $promptParts[] = $a;
            }
        }
        return [trim(implode(' ', $promptParts)), $opts];
    }

    private function usage(Renderer $renderer): int
    {
        $renderer->line('Usage:');
        $renderer->line('  superagent auto "<task>" [--model <m>] [--provider <p>]');
        $renderer->line('                           [--analyze-only] [--verbose]');
        $renderer->line('');
        $renderer->line('Auto mode uses keyword + structure heuristics to decide single-vs-multi-agent');
        $renderer->line('and runs the same model on every subtask. For eval-score-driven routing,');
        $renderer->line('use `superagent smart` instead.');
        return 2;
    }
}
