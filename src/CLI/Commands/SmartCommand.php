<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Evals\ScoreCatalog;
use SuperAgent\Evals\SmartOrchestrator;
use SuperAgent\Exceptions\BudgetExceededException;

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
 *   superagent smart "<task>"                         run end-to-end
 *   superagent smart "<task>" --dry-run               print the plan only
 *   superagent smart "<task>" --brain <model>         override the brain model
 *   superagent smart "<task>" --threshold <0..1>      easy-model score floor
 *   superagent smart "<task>" --max-cost <usd>        abort if running cost exceeds
 *   superagent smart "<task>" --max-parallel <n>      cap concurrent subprocesses (0=unlimited)
 *   superagent smart "<task>" --yes                   skip the cost confirmation
 *   superagent smart "<task>" --json                  print the full run as JSON
 *   superagent smart show                             list recent smart_runs
 *   superagent smart show <id|--last>                 print one run's summary
 *   superagent smart replay <id> [--brain X] [--threshold N]
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
            return $this->show($renderer, array_slice($args, 1));
        }
        if (strtolower($first) === 'replay') {
            return $this->replay($renderer, array_slice($args, 1));
        }
        return $this->runSmart($renderer, $args);
    }

    private function runSmart(Renderer $renderer, array $args): int
    {
        [$task, $opts] = $this->parseArgs($args);
        if ($task === '') {
            return $this->usage($renderer);
        }

        $jsonMode = $opts['json'] ?? false;
        // In --json mode, all human chatter goes to stderr so stdout stays clean
        // for piping (`superagent smart "..." --json | jq`).
        $emitTo = $jsonMode ? STDERR : STDOUT;

        $catalog = ScoreCatalog::default();
        if (empty($catalog->load()['models']) && ! ($opts['yes'] ?? false)) {
            $renderer->warning('No eval scores found yet — smart mode will fall back to the configured default model for every step.');
            $renderer->hint('Run `superagent eval run` first to get meaningful routing.');
            if (! $renderer->confirm('Continue anyway?', false)) {
                return 0;
            }
        }

        try {
            $orchestrator = new SmartOrchestrator(
                catalog: $catalog,
                brainOverride: $opts['brain'] ?? null,
                easyThreshold: $opts['threshold'] ?? SmartOrchestrator::DEFAULT_EASY_THRESHOLD,
                onEvent: function (array $event) use ($renderer, $emitTo) {
                    $this->renderEvent($renderer, $event, $emitTo);
                },
                maxCostUsd: $opts['max_cost'] ?? null,
                maxParallel: $opts['max_parallel'] ?? SmartOrchestrator::DEFAULT_MAX_PARALLEL,
                onMergeDelta: $jsonMode ? null : function (string $delta): void {
                    // Stream merge tokens straight to stdout so users see the
                    // final answer take shape instead of a long silent pause.
                    echo $delta;
                    @flush();
                },
            );
        } catch (\InvalidArgumentException $e) {
            $renderer->error($e->getMessage());
            return 2;
        }

        if ($opts['dry_run'] ?? false) {
            $plan = $orchestrator->planOnly($task);
            $this->renderPlan($renderer, $plan, $emitTo);
            return 0;
        }

        if (! $jsonMode) {
            $renderer->newLine();
        }

        try {
            $result = $orchestrator->run($task);
        } catch (BudgetExceededException $e) {
            $renderer->error(sprintf(
                'Aborted — budget cap of $%.4f exceeded (spent $%.4f).',
                $e->budget, $e->spent,
            ));
            return 3;
        }

        if ($jsonMode) {
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

    /**
     * `smart show`              — list recent runs
     * `smart show <id|file>`    — print a single run's summary
     * `smart show --last`       — print the most recent run's summary
     */
    private function show(Renderer $renderer, array $args): int
    {
        $dir = SmartOrchestrator::defaultRunLogDir();
        $files = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []) : [];
        if (empty($files)) {
            $renderer->info('No smart runs yet.');
            return 0;
        }
        rsort($files);

        $target = $args[0] ?? null;
        if ($target === null) {
            $renderer->info('Recent smart runs (newest first):');
            foreach (array_slice($files, 0, 20) as $f) {
                $renderer->line('  ' . basename($f, '.json'));
            }
            $renderer->hint('Dir: ' . $dir);
            $renderer->hint('Inspect one: superagent smart show <id>  or  smart show --last');
            return 0;
        }

        $path = $this->resolveRunFile($dir, $files, $target);
        if ($path === null) {
            $renderer->error("No run found matching: {$target}");
            return 2;
        }
        $payload = json_decode((string) @file_get_contents($path), true);
        if (! is_array($payload)) {
            $renderer->error("Run file is unreadable or corrupt: {$path}");
            return 2;
        }
        $this->renderRunSummary($renderer, $payload, $path);
        return 0;
    }

    /**
     * `smart replay <id|--last> [--brain X] [--threshold N] [--max-cost N]`
     */
    private function replay(Renderer $renderer, array $args): int
    {
        if (empty($args)) {
            $renderer->line('Usage: superagent smart replay <id|--last> [--brain X] [--threshold N] [--max-cost N]');
            return 2;
        }

        $dir = SmartOrchestrator::defaultRunLogDir();
        $files = is_dir($dir) ? (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []) : [];
        if (empty($files)) {
            $renderer->error('No smart runs to replay.');
            return 2;
        }
        rsort($files);

        $target = $args[0];
        $rest = array_slice($args, 1);
        $path = $this->resolveRunFile($dir, $files, $target);
        if ($path === null) {
            $renderer->error("No run found matching: {$target}");
            return 2;
        }
        $payload = json_decode((string) @file_get_contents($path), true);
        if (! is_array($payload) || ! is_array($payload['plan'] ?? null) || ! is_string($payload['task'] ?? null)) {
            $renderer->error("Run file is missing 'task' or 'plan': {$path}");
            return 2;
        }

        // Reuse the same flag parser so --brain/--threshold/--max-cost behave identically.
        [$_, $opts] = $this->parseArgs($rest);

        try {
            $orchestrator = new SmartOrchestrator(
                catalog: ScoreCatalog::default(),
                brainOverride: $opts['brain'] ?? null,
                easyThreshold: $opts['threshold'] ?? SmartOrchestrator::DEFAULT_EASY_THRESHOLD,
                onEvent: function (array $event) use ($renderer): void {
                    $this->renderEvent($renderer, $event, STDOUT);
                },
                maxCostUsd: $opts['max_cost'] ?? null,
                maxParallel: $opts['max_parallel'] ?? SmartOrchestrator::DEFAULT_MAX_PARALLEL,
                onMergeDelta: function (string $delta): void {
                    echo $delta;
                    @flush();
                },
            );
        } catch (\InvalidArgumentException $e) {
            $renderer->error($e->getMessage());
            return 2;
        }

        $renderer->info('Replaying plan from ' . basename($path, '.json'));
        $renderer->newLine();
        try {
            $result = $orchestrator->replayFromPlan($payload['task'], $payload['plan']);
        } catch (BudgetExceededException $e) {
            $renderer->error(sprintf(
                'Aborted — budget cap of $%.4f exceeded (spent $%.4f).',
                $e->budget, $e->spent,
            ));
            return 3;
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

    /**
     * Match `<id>` against the set of run-log files. Accepts:
     *   - `--last` / `last` → newest file
     *   - the bare basename without `.json`  (e.g. `2026-05-12_103045_abc123`)
     *   - a substring prefix              (e.g. `2026-05-12` → first match)
     *   - the full path
     *
     * @param list<string> $files
     */
    private function resolveRunFile(string $dir, array $files, string $target): ?string
    {
        if ($target === '--last' || strtolower($target) === 'last') {
            return $files[0] ?? null;
        }
        if (is_file($target)) {
            return $target;
        }
        $direct = $dir . DIRECTORY_SEPARATOR . $target . '.json';
        if (is_file($direct)) {
            return $direct;
        }
        foreach ($files as $f) {
            if (str_contains(basename($f), $target)) {
                return $f;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $payload */
    private function renderRunSummary(Renderer $renderer, array $payload, string $path): void
    {
        $renderer->info('Run: ' . basename($path, '.json'));
        $renderer->line('  ran_at:   ' . (string) ($payload['ran_at'] ?? '?'));
        $renderer->line('  brain:    ' . (string) ($payload['brain'] ?? '?'));
        $renderer->line('  cost:     $' . sprintf('%.4f', (float) ($payload['total_cost_usd'] ?? 0.0)));
        $renderer->line('  latency:  ' . ((int) ($payload['total_latency_ms'] ?? 0)) . 'ms');
        $renderer->line('  task:     ' . $this->truncate((string) ($payload['task'] ?? ''), 200));
        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : null;
        if ($plan !== null) {
            $renderer->newLine();
            $this->renderPlan($renderer, $plan, STDOUT);
        }
        $renderer->newLine();
        $renderer->info('Subtask outputs:');
        foreach ((array) ($payload['subtask_results'] ?? []) as $sr) {
            if (! is_array($sr)) {
                continue;
            }
            $renderer->line(sprintf(
                '  [%s] %s/%s → %s  (%dms, $%.4f)',
                (string) ($sr['id'] ?? '?'),
                (string) ($sr['difficulty'] ?? '?'),
                (string) ($sr['dim'] ?? '?'),
                (string) ($sr['model'] ?? '?'),
                (int) ($sr['latency_ms'] ?? 0),
                (float) ($sr['cost_usd'] ?? 0.0),
            ));
            $renderer->line('      ' . $this->truncate((string) ($sr['output'] ?? ''), 160));
        }
        $renderer->newLine();
        $renderer->hint('File: ' . $path);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function parseArgs(array $args): array
    {
        $task = '';
        $opts = [
            'brain'        => null,
            'threshold'    => null,
            'dry_run'      => false,
            'yes'          => false,
            'json'         => false,
            'max_cost'     => null,
            'max_parallel' => null,
        ];

        $promptParts = [];
        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            if ($a === '--brain') {
                $opts['brain'] = (string) ($args[++$i] ?? '');
            } elseif ($a === '--threshold') {
                $val = (float) ($args[++$i] ?? '0.6');
                $opts['threshold'] = max(0.0, min(1.0, $val));
            } elseif ($a === '--max-cost') {
                $val = (float) ($args[++$i] ?? '0');
                $opts['max_cost'] = $val > 0 ? $val : null;
            } elseif ($a === '--max-parallel') {
                $val = (int) ($args[++$i] ?? '0');
                $opts['max_parallel'] = max(0, $val);
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

    /**
     * @param array<string, mixed> $plan
     * @param resource $emitTo
     */
    private function renderPlan(Renderer $renderer, array $plan, $emitTo): void
    {
        $line = sprintf(
            'Plan: complexity=%s · primary_dim=%s · concurrency=%s · %d subtasks',
            $plan['complexity'],
            $plan['primary_dim'],
            $plan['concurrency'],
            count($plan['subtasks']),
        );
        $this->emitLine($renderer, $emitTo, fn () => $renderer->info($line), $line);

        foreach ($plan['subtasks'] as $st) {
            $head = sprintf('  [%s] difficulty=%s dim=%s', $st['id'], $st['difficulty'], $st['dim']);
            $body = '      ' . $this->truncate((string) $st['prompt'], 120);
            $this->emitLine($renderer, $emitTo, fn () => $renderer->line($head), $head);
            $this->emitLine($renderer, $emitTo, fn () => $renderer->line($body), $body);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param resource $emitTo
     */
    private function renderEvent(Renderer $renderer, array $event, $emitTo): void
    {
        $type = (string) ($event['type'] ?? '');
        switch ($type) {
            case 'brain_picked':
                $this->emitLine($renderer, $emitTo, fn () => $renderer->info('▶ brain: ' . $event['model']), '▶ brain: ' . $event['model']);
                break;
            case 'planning_started':
                $this->emitLine($renderer, $emitTo, fn () => $renderer->line('  · planning…'), '  · planning…');
                break;
            case 'plan':
            case 'plan_replayed':
                $this->renderPlan($renderer, $event['plan'], $emitTo);
                break;
            case 'plan_retry':
                $msg = '  · plan parse failed, retrying once (' . ($event['reason'] ?? '') . ')';
                $this->emitLine($renderer, $emitTo, fn () => $renderer->line($msg), $msg);
                break;
            case 'parallel_started':
                $cap = (int) ($event['cap'] ?? 0);
                $msg = '  · parallel: ' . (int) ($event['count'] ?? 0) . ' subtasks'
                    . ($cap > 0 ? " (cap={$cap})" : ' (uncapped)');
                $this->emitLine($renderer, $emitTo, fn () => $renderer->line($msg), $msg);
                break;
            case 'subtask_routed':
                $msg = sprintf(
                    '  ⮕ subtask %s [%s/%s] → %s',
                    $event['id'], $event['difficulty'], $event['dim'], $event['model'],
                );
                $this->emitLine($renderer, $emitTo, fn () => $renderer->line($msg), $msg);
                break;
            case 'subtask_done':
                $msg = sprintf(
                    '    ✓ %s  (%dms, $%.4f)',
                    $event['id'], $event['latency_ms'], $event['cost_usd'],
                );
                $this->emitLine($renderer, $emitTo, fn () => $renderer->line($msg), $msg);
                break;
            case 'subtask_error':
                $msg = '    ✗ ' . ($event['id'] ?? '?') . '  ' . (string) ($event['error'] ?? '');
                $this->emitLine($renderer, $emitTo, fn () => $renderer->error($msg), $msg);
                break;
            case 'subtask_cancelled':
                $msg = '    × cancelled ' . (string) ($event['id'] ?? '?') . ' — ' . (string) ($event['reason'] ?? '');
                $this->emitLine($renderer, $emitTo, fn () => $renderer->warning($msg), $msg);
                break;
            case 'budget_exceeded':
                $msg = sprintf(
                    '  ! budget cap hit at %s — spent $%.4f / $%.4f',
                    (string) ($event['stage'] ?? '?'),
                    (float) ($event['spent_usd'] ?? 0),
                    (float) ($event['cap_usd'] ?? 0),
                );
                $this->emitLine($renderer, $emitTo, fn () => $renderer->warning($msg), $msg);
                break;
            case 'merging_started':
                $this->emitLine($renderer, $emitTo, fn () => $renderer->line('  · merging…'), '  · merging…');
                break;
            case 'merge_skipped':
                $msg = '  · merge skipped (' . $event['reason'] . ')';
                $this->emitLine($renderer, $emitTo, fn () => $renderer->line($msg), $msg);
                break;
            case 'parallel_fallback':
                $msg = '  · parallel unavailable, falling back to serial (' . $event['reason'] . ')';
                $this->emitLine($renderer, $emitTo, fn () => $renderer->warning($msg), $msg);
                break;
            case 'run_persisted':
                // Quiet — the path is printed in the final summary.
                break;
        }
    }

    /**
     * Renderer always writes to stdout. When `$emitTo` is stderr (JSON mode),
     * bypass the renderer and write a plain string instead.
     *
     * @param resource $emitTo
     */
    private function emitLine(Renderer $renderer, $emitTo, \Closure $rich, string $plain): void
    {
        if ($emitTo === STDOUT) {
            $rich();
        } else {
            fwrite($emitTo, $plain . PHP_EOL);
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
        $renderer->line('                            [--max-cost <usd>] [--max-parallel <n>]');
        $renderer->line('                            [--dry-run] [--yes] [--json]');
        $renderer->line('  superagent smart show                List recent smart-run logs');
        $renderer->line('  superagent smart show <id|--last>    Print one run\'s summary');
        $renderer->line('  superagent smart replay <id|--last>  Re-execute a saved plan');
        $renderer->line('');
        $renderer->line('Smart mode reads ~/.superagent/model_scores.json (from `superagent eval run`)');
        $renderer->line('to pick a brain model, plan + split the task, route subtasks by score, and merge.');
        return 2;
    }
}
