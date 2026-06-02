<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\SmartFlow\FlowEngine;
use SuperAgent\SmartFlow\FlowOptions;
use SuperAgent\SmartFlow\FlowRegistry;

/**
 * `superagent flow` — run cross-model SmartFlow workflows.
 *
 * The multi-ai dynamic-flow CLI: the same primitives (agent/parallel/pipeline/
 * gate/budget) drive any provider. Static flows ship as YAML under
 * resources/flows; `--rehearse` runs any flow end-to-end at zero token cost.
 *
 * Usage:
 *   superagent flow list                              list available flows
 *   superagent flow show <name>                       show a flow's metadata
 *   superagent flow plan <name>                       dry-run plan (no model calls)
 *   superagent flow run <name> [options]              execute a flow
 *
 * Run options:
 *   --args k=v            set an arg (repeatable)
 *   --json '{...}'        set args from a JSON object
 *   --rehearse | --fake   use the deterministic zero-cost fake provider
 *   --dry-run             rehearse without writing a ledger file
 *   --resume <runId>      replay the unchanged prefix of a prior run
 *   --concurrency <n>     max parallel workers (process pool)
 *   --budget-usd <x>      hard USD ceiling
 *   --provider <p>        default provider for calls without one
 *   --model <m>           default model
 *   --out-json            print the full result as JSON
 */
final class FlowCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['flow_args'] ?? [];
        $sub = strtolower((string) ($args[0] ?? ''));

        return match ($sub) {
            '', 'list', 'ls' => $this->list($renderer),
            'show' => $this->show($renderer, (string) ($args[1] ?? '')),
            'plan' => $this->run($renderer, array_slice($args, 1), planOnly: true),
            'run' => $this->run($renderer, array_slice($args, 1), planOnly: false),
            default => $this->usage($renderer),
        };
    }

    private function list(Renderer $renderer): int
    {
        $registry = new FlowRegistry();
        $flows = $registry->list();

        if ($flows === []) {
            $renderer->warning('No flows found.');
            $renderer->hint('Built-in flows live in resources/flows/*.yaml; add your own under ./flows or ./.superagent/flows.');
            return 0;
        }

        $renderer->info(sprintf('SmartFlow — %d flow%s available', count($flows), count($flows) === 1 ? '' : 's'));
        $renderer->newLine();
        $width = max(array_map('strlen', array_keys($flows)));
        foreach ($flows as $name => $meta) {
            $desc = $this->firstLine($meta['description']);
            $renderer->line(sprintf('  %-' . $width . 's  %s', $name, $desc));
        }
        $renderer->newLine();
        $renderer->hint('Run one with:  superagent flow run <name> --args key=value');
        $renderer->hint('Rehearse free: superagent flow run <name> --rehearse');
        return 0;
    }

    private function show(Renderer $renderer, string $name): int
    {
        if ($name === '') {
            $renderer->error('Usage: superagent flow show <name>');
            return 2;
        }
        $registry = new FlowRegistry();
        if (!$registry->has($name)) {
            $renderer->error("Flow '{$name}' not found.");
            return 2;
        }
        $def = $registry->get($name);
        if ($def === null) {
            $renderer->error("Flow '{$name}' could not be loaded.");
            return 2;
        }

        $renderer->info("Flow: {$def->name}");
        $renderer->line($def->description);
        if ($def->phases !== []) {
            $renderer->newLine();
            $renderer->line('Phases:');
            foreach ($def->phases as $p) {
                $renderer->line('  • ' . ($p['title'] ?? ''));
            }
        }
        if ($def->defaults !== []) {
            $renderer->newLine();
            $renderer->line('Defaults: ' . json_encode($def->defaults, JSON_UNESCAPED_SLASHES));
        }
        if ($def->source !== null) {
            $renderer->newLine();
            $renderer->hint('Source: ' . $def->source);
        }
        return 0;
    }

    private function run(Renderer $renderer, array $args, bool $planOnly): int
    {
        $name = '';
        $flowArgs = [];
        $opts = new FlowOptions();
        $outJson = false;

        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            if ($a === '--args' || $a === '-a') {
                $pair = (string) ($args[++$i] ?? '');
                $eq = strpos($pair, '=');
                if ($eq !== false) {
                    $flowArgs[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
                }
            } elseif ($a === '--json') {
                $decoded = json_decode((string) ($args[++$i] ?? ''), true);
                if (is_array($decoded)) {
                    $flowArgs = array_merge($flowArgs, $decoded);
                }
            } elseif ($a === '--rehearse' || $a === '--fake') {
                $opts->rehearse = true;
            } elseif ($a === '--dry-run') {
                $opts->dryRun = true;
            } elseif ($a === '--resume') {
                $opts->resumeRunId = (string) ($args[++$i] ?? '');
            } elseif ($a === '--concurrency') {
                $opts->concurrency = (int) ($args[++$i] ?? 1);
            } elseif ($a === '--budget-usd') {
                $opts->budgetUsd = (float) ($args[++$i] ?? 0);
            } elseif ($a === '--provider' || $a === '-p') {
                $opts->defaultProvider = (string) ($args[++$i] ?? '');
            } elseif ($a === '--model' || $a === '-m') {
                $opts->defaultModel = (string) ($args[++$i] ?? '');
            } elseif ($a === '--out-json') {
                $outJson = true;
            } elseif ($name === '' && !str_starts_with($a, '-')) {
                $name = $a;
            }
        }

        if ($name === '') {
            $renderer->error('Usage: superagent flow run <name> [--args k=v] [--rehearse]');
            return 2;
        }

        $registry = new FlowRegistry();
        $def = $registry->get($name);
        if ($def === null) {
            $renderer->error("Flow '{$name}' not found. Try: superagent flow list");
            return 2;
        }

        if ($planOnly) {
            return $this->show($renderer, $name);
        }

        $engine = new FlowEngine();
        $result = $engine->run($def, $flowArgs, $opts);

        if ($outJson) {
            $renderer->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $result->isSuccessful() ? 0 : 1;
        }

        $tag = $result->fake ? ' [rehearsal]' : '';
        if ($result->isSuccessful()) {
            $renderer->success("Flow '{$name}' completed{$tag}");
        } else {
            $renderer->error("Flow '{$name}' failed: " . ($result->error ?? 'unknown'));
        }

        $l = $result->ledger;
        $renderer->newLine();
        $renderer->line(sprintf(
            '  calls: %d (cached %d, skips %d)   cost: $%.4f   tokens: %d in / %d out',
            $l['calls'] ?? 0,
            $l['cached_calls'] ?? 0,
            $l['skips'] ?? 0,
            $l['cost_usd'] ?? 0,
            $l['input_tokens'] ?? 0,
            $l['output_tokens'] ?? 0,
        ));
        if (!empty($l['layers'])) {
            $renderer->line('  layers: ' . json_encode($l['layers'], JSON_UNESCAPED_SLASHES));
        }
        $renderer->line('  run id: ' . $result->runId);
        if ($result->ledgerPath !== null) {
            $renderer->hint('  ledger: ' . $result->ledgerPath . '   (resume with --resume ' . $result->runId . ')');
        }

        return $result->isSuccessful() ? 0 : 1;
    }

    private function usage(Renderer $renderer): int
    {
        $renderer->info('superagent flow — cross-model dynamic flows');
        $renderer->newLine();
        $renderer->line('  flow list                       list available flows');
        $renderer->line('  flow show <name>                show a flow');
        $renderer->line('  flow run <name> [options]       run a flow');
        $renderer->newLine();
        $renderer->line('Options: --args k=v  --json {..}  --rehearse  --resume <id>  --concurrency <n>  --budget-usd <x>  --out-json');
        return 0;
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');
        return mb_strlen($line) > 80 ? mb_substr($line, 0, 77) . '...' : $line;
    }
}
