<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Evals\ScoreCatalog;
use SuperAgent\Evals\SmartOrchestrator;

/**
 * `superagent smart` — eval-score-driven task orchestration.
 *
 * Reads `~/.superagent/model_scores.json` (produced by `superagent eval run`)
 * to pick the most capable "brain" model, has it produce a JSON execution
 * plan, dispatches each subtask to the right model by (difficulty × dim),
 * and merges the outputs.
 *
 * Distinct from the legacy `AutoMode` feature (keyword heuristics, runs
 * full sub-Agents). See SmartOrchestrator's docblock for the full pipeline.
 *
 * Usage:
 *   superagent smart "<task>"                        run end-to-end
 *   superagent smart "<task>" --dry-run              print the plan only
 *   superagent smart "<task>" --brain <model>        override the brain model
 *   superagent smart "<task>" --threshold <0..1>     easy-model score floor
 *   superagent smart "<task>" --yes                  skip the cost confirmation
 *   superagent smart "<task>" --json                 print the full run as JSON
 *   superagent smart show                            list recent smart_runs
 */
final class SmartCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['smart_args'] ?? [];
        $first = (string) ($args[0] ?? '');

        if ($first === '' || str_starts_with($first, '-')) {
            return $this->usage($renderer);
        }
        if (strtolower($first) === 'show') {
            return $this->showRecent($renderer);
        }
        return $this->runSmart($renderer, $args);
    }

    private function runSmart(Renderer $renderer, array $args): int
    {
        [$task, $opts] = $this->parseArgs($args);
        if ($task === '') {
            return $this->usage($renderer);
        }

        $catalog = ScoreCatalog::default();
        if (empty($catalog->load()['models']) && ! ($opts['yes'] ?? false)) {
            $renderer->warning('No eval scores found yet — smart mode will fall back to the configured default model for every step.');
            $renderer->hint('Run `superagent eval run` first to get meaningful routing.');
            if (! $renderer->confirm('Continue anyway?', false)) {
                return 0;
            }
        }

        $orchestrator = new SmartOrchestrator(
            catalog: $catalog,
            brainOverride: $opts['brain'] ?? null,
            easyThreshold: $opts['threshold'] ?? SmartOrchestrator::DEFAULT_EASY_THRESHOLD,
            onEvent: function (array $event) use ($renderer) {
                $this->renderEvent($renderer, $event);
            },
        );

        if ($opts['dry_run'] ?? false) {
            $plan = $orchestrator->planOnly($task);
            $this->renderPlan($renderer, $plan);
            return 0;
        }

        $renderer->newLine();
        $result = $orchestrator->run($task);

        if ($opts['json'] ?? false) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }

        $renderer->newLine();
        $renderer->separator();
        $renderer->info('Final answer:');
        $renderer->separator();
        $renderer->assistantMessage($result['final']);
        $renderer->newLine();
        $renderer->separator();
        $renderer->cost($result['total_cost_usd'], count($result['subtask_results']));
        if (! empty($result['run_log_path'])) {
            $renderer->hint('Run log: ' . $result['run_log_path']);
        }
        return 0;
    }

    private function showRecent(Renderer $renderer): int
    {
        $dir = SmartOrchestrator::defaultRunLogDir();
        if (! is_dir($dir)) {
            $renderer->info('No smart runs yet.');
            return 0;
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if (empty($files)) {
            $renderer->info('No smart runs yet.');
            return 0;
        }
        rsort($files);
        $renderer->info('Recent smart runs (newest first):');
        foreach (array_slice($files, 0, 20) as $f) {
            $renderer->line('  ' . basename($f));
        }
        $renderer->hint('Dir: ' . $dir);
        return 0;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseArgs(array $args): array
    {
        $task = '';
        $opts = [
            'brain'     => null,
            'threshold' => null,
            'dry_run'   => false,
            'yes'       => false,
            'json'      => false,
        ];

        $promptParts = [];
        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            if ($a === '--brain') {
                $opts['brain'] = (string) ($args[++$i] ?? '');
            } elseif ($a === '--threshold') {
                $val = (float) ($args[++$i] ?? '0.6');
                $opts['threshold'] = max(0.0, min(1.0, $val));
            } elseif ($a === '--dry-run') {
                $opts['dry_run'] = true;
            } elseif ($a === '--yes' || $a === '-y') {
                $opts['yes'] = true;
            } elseif ($a === '--json') {
                $opts['json'] = true;
            } elseif (! str_starts_with($a, '-')) {
                $promptParts[] = $a;
            }
        }
        $task = trim(implode(' ', $promptParts));
        return [$task, $opts];
    }

    /** @param array<string, mixed> $plan */
    private function renderPlan(Renderer $renderer, array $plan): void
    {
        $renderer->info(sprintf(
            'Plan: complexity=%s · primary_dim=%s · concurrency=%s · %d subtasks',
            $plan['complexity'],
            $plan['primary_dim'],
            $plan['concurrency'],
            count($plan['subtasks']),
        ));
        foreach ($plan['subtasks'] as $st) {
            $renderer->line(sprintf(
                '  [%s] difficulty=%s dim=%s',
                $st['id'], $st['difficulty'], $st['dim'],
            ));
            $renderer->line('      ' . $this->truncate((string) $st['prompt'], 120));
        }
    }

    /** @param array<string, mixed> $event */
    private function renderEvent(Renderer $renderer, array $event): void
    {
        switch ((string) ($event['type'] ?? '')) {
            case 'brain_picked':
                $renderer->info('▶ brain: ' . $event['model']);
                break;
            case 'planning_started':
                $renderer->line('  · planning…');
                break;
            case 'plan':
                $this->renderPlan($renderer, $event['plan']);
                break;
            case 'subtask_routed':
                $renderer->line(sprintf(
                    '  ⮕ subtask %s [%s/%s] → %s',
                    $event['id'], $event['difficulty'], $event['dim'], $event['model'],
                ));
                break;
            case 'subtask_done':
                $renderer->line(sprintf(
                    '    ✓ %s  (%dms, $%.4f)',
                    $event['id'], $event['latency_ms'], $event['cost_usd'],
                ));
                break;
            case 'merging_started':
                $renderer->line('  · merging…');
                break;
            case 'merge_skipped':
                $renderer->line('  · merge skipped (' . $event['reason'] . ')');
                break;
            case 'run_persisted':
                // Quiet — the path is printed in the final summary.
                break;
        }
    }

    private function truncate(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }

    private function usage(Renderer $renderer): int
    {
        $renderer->line('Usage:');
        $renderer->line('  superagent smart "<task>" [--brain <model>] [--threshold 0.6]');
        $renderer->line('                            [--dry-run] [--yes] [--json]');
        $renderer->line('  superagent smart show     List recent smart-run logs');
        $renderer->line('');
        $renderer->line('Smart mode reads ~/.superagent/model_scores.json (from `superagent eval run`)');
        $renderer->line('to pick a brain model, plan + split the task, route subtasks by score, and merge.');
        return 2;
    }
}
