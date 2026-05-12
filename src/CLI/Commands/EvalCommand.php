<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Config\ConfigRepository;
use SuperAgent\Evals\DimensionLoader;
use SuperAgent\Evals\EvalRunner;
use SuperAgent\Evals\ScoreCatalog;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ProviderRegistry;

/**
 * `superagent eval` — manually run capability evaluations against one or more
 * models on one or more dimensions, then persist the scores to
 * `~/.superagent/model_scores.json` so the routing layer (AutoModelStrategy)
 * can consult them.
 *
 * Subcommands:
 *   run     [--models a,b] [--dims x,y] [--judge <model>] [--yes]
 *           Run the evals. Without --models/--dims the user is prompted
 *           interactively. --yes skips the cost confirmation.
 *   list    Show available dimensions and their case counts.
 *   show    Print the current `model_scores.json` as a table.
 *   path    Print where the score file lives.
 *
 * Each (model, dim) result OVERWRITES the previous one for that pair — the
 * file is a current-state snapshot, not a history log. This is intentional:
 * the routing layer only cares about the freshest measurement.
 */
final class EvalCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['eval_args'] ?? [];
        $sub = strtolower((string) ($args[0] ?? 'run'));
        $rest = array_slice($args, 1);

        return match ($sub) {
            'run'   => $this->run($renderer, $rest),
            'list'  => $this->list($renderer),
            'show'  => $this->show($renderer),
            'path'  => $this->path($renderer),
            default => $this->usage($renderer, $sub),
        };
    }

    private function run(Renderer $renderer, array $rest): int
    {
        $loader = new DimensionLoader();
        $catalog = ScoreCatalog::default();

        $opts = $this->parseRunOpts($rest);

        $modelIds = $opts['models'] ?? $this->pickModels($renderer);
        if (empty($modelIds)) {
            $renderer->error('No models selected.');
            return 1;
        }

        $dims = $opts['dims'] ?? $this->pickDims($renderer, $loader);
        if (empty($dims)) {
            $renderer->error('No dimensions selected.');
            return 1;
        }

        $totalCases = 0;
        foreach ($dims as $d) {
            try {
                $totalCases += count($loader->load($d)['cases']);
            } catch (\Throwable) {
                // skip — we'll report below
            }
        }
        $renderer->info(sprintf(
            'About to run %d cases × %d models = %d LLM calls.',
            $totalCases,
            count($modelIds),
            $totalCases * count($modelIds),
        ));
        if (! ($opts['yes'] ?? false) && ! $renderer->confirm('Proceed?', true)) {
            $renderer->info('Aborted.');
            return 0;
        }

        $judge = null;
        $judgeId = $opts['judge'] ?? null;
        if ($judgeId !== null) {
            try {
                $judge = $this->buildJudge($judgeId);
                $renderer->info("Using judge model: {$judgeId}");
            } catch (\Throwable $e) {
                $renderer->warning("Judge unavailable ({$e->getMessage()}); judge-scored cases will fail.");
            }
        }

        $runner = new EvalRunner($loader, $catalog, $judge, function (array $event) use ($renderer) {
            $this->renderEvent($renderer, $event);
        });

        $renderer->newLine();
        $renderer->separator();
        $renderer->info('Starting eval run…');
        $renderer->separator();

        $summary = $runner->run($modelIds, $dims);

        $renderer->newLine();
        $renderer->separator();
        $renderer->info('Run complete. Latest scores:');
        $this->renderMatrix($renderer, $summary, $dims);
        $renderer->newLine();
        $renderer->hint('Saved to: ' . $catalog->path());
        return 0;
    }

    private function list(Renderer $renderer): int
    {
        $loader = new DimensionLoader();
        $names = $loader->available();
        if (empty($names)) {
            $renderer->warning('No eval dimensions found.');
            return 1;
        }
        $renderer->info('Available dimensions:');
        foreach ($names as $name) {
            try {
                $def = $loader->load($name);
                $renderer->line(sprintf('  %-25s  %d cases', $name, count($def['cases'])));
            } catch (\Throwable $e) {
                $renderer->line(sprintf('  %-25s  (load error: %s)', $name, $e->getMessage()));
            }
        }
        return 0;
    }

    private function show(Renderer $renderer): int
    {
        $catalog = ScoreCatalog::default();
        $data = $catalog->load();
        $models = $data['models'] ?? [];
        if (empty($models)) {
            $renderer->info('No scores yet. Run `superagent eval run` first.');
            return 0;
        }

        $dimSet = [];
        foreach ($models as $entry) {
            foreach (($entry['dims'] ?? []) as $dim => $_) {
                $dimSet[$dim] = true;
            }
        }
        $dims = array_keys($dimSet);
        sort($dims);

        $summary = [];
        foreach ($models as $id => $entry) {
            foreach ($dims as $dim) {
                if (isset($entry['dims'][$dim])) {
                    $summary[$id][$dim] = $entry['dims'][$dim];
                }
            }
        }
        $this->renderMatrix($renderer, $summary, $dims);
        $renderer->newLine();
        $renderer->hint('File: ' . $catalog->path());
        return 0;
    }

    private function path(Renderer $renderer): int
    {
        $renderer->line(ScoreCatalog::default()->path());
        return 0;
    }

    // --- Helpers --------------------------------------------------------

    /** @return array<string, mixed> */
    private function parseRunOpts(array $rest): array
    {
        $opts = [];
        for ($i = 0; $i < count($rest); $i++) {
            $arg = $rest[$i];
            if ($arg === '--models' || $arg === '-m') {
                $opts['models'] = array_values(array_filter(array_map('trim', explode(',', (string) ($rest[++$i] ?? '')))));
            } elseif ($arg === '--dims' || $arg === '-d') {
                $opts['dims'] = array_values(array_filter(array_map('trim', explode(',', (string) ($rest[++$i] ?? '')))));
            } elseif ($arg === '--judge') {
                $opts['judge'] = (string) ($rest[++$i] ?? '');
            } elseif ($arg === '--yes' || $arg === '-y') {
                $opts['yes'] = true;
            }
        }
        return $opts;
    }

    /** @return list<string> */
    private function pickModels(Renderer $renderer): array
    {
        $providers = ModelCatalog::providers();
        $configured = [];
        foreach ($providers as $p) {
            $cfg = ConfigRepository::getInstance()->get("superagent.providers.{$p}", []);
            if (is_array($cfg) && ! empty($cfg['api_key'])) {
                $configured[$p] = true;
            }
        }

        $candidates = [];
        foreach ($providers as $p) {
            if (! isset($configured[$p])) {
                continue;
            }
            foreach (ModelCatalog::modelsFor($p) as $m) {
                if (isset($m['id'])) {
                    $candidates[] = $m['id'];
                }
            }
        }
        if (empty($candidates)) {
            $renderer->warning('No configured providers have API keys set. Run `superagent init` or export env vars first.');
            $renderer->hint('You can also pass --models id1,id2 explicitly to skip this prompt.');
            return [];
        }

        $renderer->info('Models available for eval (from configured providers):');
        foreach ($candidates as $i => $id) {
            $renderer->line(sprintf('  [%d] %s', $i + 1, $id));
        }
        $answer = $renderer->ask('Enter numbers (comma-separated) or "all": ');
        $answer = trim($answer);
        if ($answer === '' ) {
            return [];
        }
        if (strtolower($answer) === 'all') {
            return $candidates;
        }
        $picks = [];
        foreach (explode(',', $answer) as $tok) {
            $tok = trim($tok);
            if ($tok === '' || ! ctype_digit($tok)) {
                continue;
            }
            $idx = (int) $tok - 1;
            if (isset($candidates[$idx])) {
                $picks[] = $candidates[$idx];
            }
        }
        return $picks;
    }

    /** @return list<string> */
    private function pickDims(Renderer $renderer, DimensionLoader $loader): array
    {
        $names = $loader->available();
        if (empty($names)) {
            return [];
        }
        $renderer->newLine();
        $renderer->info('Available dimensions:');
        foreach ($names as $i => $name) {
            $renderer->line(sprintf('  [%d] %s', $i + 1, $name));
        }
        $answer = trim($renderer->ask('Enter numbers (comma-separated) or "all": '));
        if ($answer === '') {
            return [];
        }
        if (strtolower($answer) === 'all') {
            return $names;
        }
        $picks = [];
        foreach (explode(',', $answer) as $tok) {
            $tok = trim($tok);
            if ($tok === '' || ! ctype_digit($tok)) {
                continue;
            }
            $idx = (int) $tok - 1;
            if (isset($names[$idx])) {
                $picks[] = $names[$idx];
            }
        }
        return $picks;
    }

    private function buildJudge(string $modelId): \SuperAgent\Contracts\LLMProvider
    {
        $entry = ModelCatalog::model($modelId);
        if ($entry === null || ! isset($entry['provider'])) {
            throw new \RuntimeException("Judge model '{$modelId}' is not in the catalog");
        }
        $provider = (string) $entry['provider'];
        $config = ConfigRepository::getInstance()->get("superagent.providers.{$provider}", []);
        $config = is_array($config) ? $config : [];
        $config['model'] = $modelId;
        return ProviderRegistry::create($provider, $config);
    }

    /** @param array<string, mixed> $event */
    private function renderEvent(Renderer $renderer, array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        switch ($type) {
            case 'model_start':
                $renderer->newLine();
                $renderer->info("▶ {$event['model']}");
                break;
            case 'model_skip':
                $renderer->warning("skip {$event['model']} — {$event['error']}");
                break;
            case 'dim_start':
                $renderer->line(sprintf('  · %s (%d cases)', $event['dim'], $event['cases']));
                break;
            case 'dim_skip':
                $renderer->line(sprintf('  · %s — skipped: %s', $event['dim'], $event['error']));
                break;
            case 'case_done':
                $mark = $event['passed'] ? '✓' : '✗';
                $color = $event['passed'] ? '32' : '31';
                $line = sprintf(
                    '    %s %s  (%dms)',
                    "\033[{$color}m{$mark}\033[0m",
                    $event['case_id'],
                    $event['latency_ms'] ?? 0,
                );
                $renderer->line($line);
                break;
            case 'case_error':
                $renderer->line(sprintf("    \033[31m✗\033[0m %s  error: %s", $event['case_id'], $event['error']));
                break;
            case 'dim_done':
                $renderer->line(sprintf(
                    '    → score %.2f  (%d/%d, avg %dms, $%.4f)',
                    $event['score'],
                    $event['passed'],
                    $event['cases'],
                    $event['latency_ms'],
                    $event['cost_usd'],
                ));
                break;
        }
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $summary
     * @param list<string> $dims
     */
    private function renderMatrix(Renderer $renderer, array $summary, array $dims): void
    {
        if (empty($summary)) {
            return;
        }
        $modelW = max(20, max(array_map('strlen', array_keys($summary))));
        $dimW = 10;

        $header = '  ' . str_pad('model', $modelW);
        foreach ($dims as $d) {
            $header .= ' | ' . str_pad(substr($d, 0, $dimW), $dimW);
        }
        $header .= ' | ' . str_pad('overall', 8);
        $renderer->line($header);
        $renderer->line('  ' . str_repeat('-', strlen($header) - 2));

        foreach ($summary as $modelId => $byDim) {
            $row = '  ' . str_pad($modelId, $modelW);
            $sum = 0.0;
            $n = 0;
            foreach ($dims as $d) {
                if (isset($byDim[$d]['score'])) {
                    $score = (float) $byDim[$d]['score'];
                    $row .= ' | ' . str_pad(number_format($score, 2), $dimW);
                    $sum += $score;
                    $n++;
                } else {
                    $row .= ' | ' . str_pad('—', $dimW);
                }
            }
            $row .= ' | ' . str_pad($n > 0 ? number_format($sum / $n, 2) : '—', 8);
            $renderer->line($row);
        }
    }

    private function usage(Renderer $renderer, string $sub): int
    {
        $renderer->error("Unknown eval subcommand: {$sub}");
        $renderer->line('');
        $renderer->line('Usage:');
        $renderer->line('  superagent eval run [--models a,b] [--dims x,y] [--judge <model>] [--yes]');
        $renderer->line('  superagent eval list                List available dimensions');
        $renderer->line('  superagent eval show                Show current model_scores.json');
        $renderer->line('  superagent eval path                Print scores file path');
        return 2;
    }
}
