<?php

declare(strict_types=1);

namespace SuperAgent\CLI\Commands;

use SuperAgent\CLI\Terminal\Renderer;
use SuperAgent\Providers\ModelCatalog;
use SuperAgent\Providers\ModelCatalogRefresher;

/**
 * `superagent models` — manage the local model catalog.
 *
 * Subcommands:
 *   list    [--provider <p>]   Print the merged (bundled + user override + runtime) catalog.
 *   update  [--url <u>]        Fetch the catalog from SUPERAGENT_MODELS_URL (or --url) and
 *                              persist atomically to ~/.superagent/models.json.
 *   status                     Show where the catalog currently comes from + when it was last updated.
 *   reset                      Delete the user override so subsequent loads fall back to the bundled file.
 *
 * The catalog backs `CostCalculator` pricing, `ModelResolver` alias resolution, and the
 * interactive `/model` picker. Users who need a specific version of the catalog can drop
 * a JSON file at `~/.superagent/models.json` directly or point SUPERAGENT_MODELS_URL at
 * any HTTPS endpoint that returns the same schema.
 */
class ModelsCommand
{
    public function execute(array $options): int
    {
        $renderer = new Renderer();
        $args = $options['models_args'] ?? [];
        $sub = strtolower((string) ($args[0] ?? 'list'));
        $rest = array_slice($args, 1);

        return match ($sub) {
            'list'    => $this->list($renderer, $rest),
            'update'  => $this->update($renderer, $rest),
            'refresh' => $this->refresh($renderer, $rest),
            'status'  => $this->status($renderer),
            'reset'   => $this->reset($renderer),
            default   => $this->usage($renderer, $sub),
        };
    }

    /**
     * `superagent models refresh [<provider>]` — hit each provider's
     * own `/models` endpoint and cache the result per-provider.
     * Differs from `update` (which pulls a single pre-built catalog
     * JSON from `SUPERAGENT_MODELS_URL`).
     */
    private function refresh(Renderer $renderer, array $rest): int
    {
        $target = $rest[0] ?? null;

        if ($target !== null && $target !== '--all') {
            $renderer->info("Refreshing /models for: {$target}");
            try {
                $models = ModelCatalogRefresher::refresh($target);
            } catch (\Throwable $e) {
                $renderer->error('Refresh failed: ' . $e->getMessage());
                return 1;
            }
            $renderer->success(sprintf(
                "Cached %d %s models → %s",
                count($models),
                $target,
                ModelCatalogRefresher::cachePath($target),
            ));
            ModelCatalog::invalidate();
            return 0;
        }

        $renderer->info('Refreshing /models for every provider with env credentials…');
        $results = ModelCatalogRefresher::refreshAll();
        ModelCatalog::invalidate();

        $ok = 0;
        $fail = 0;
        foreach ($results as $p => $r) {
            if ($r['ok'] ?? false) {
                $renderer->line(sprintf('  %-11s  %d models', $p, $r['count']));
                $ok++;
            } else {
                $renderer->line(sprintf('  %-11s  skipped: %s', $p, $r['error'] ?? 'unknown'));
                $fail++;
            }
        }
        $renderer->newLine();
        $renderer->info(sprintf('Done: %d ok, %d skipped.', $ok, $fail));
        return $ok > 0 ? 0 : 1;
    }

    private function list(Renderer $renderer, array $rest): int
    {
        $providerFilter = null;
        for ($i = 0; $i < count($rest); $i++) {
            if ($rest[$i] === '--provider' || $rest[$i] === '-p') {
                $providerFilter = $rest[++$i] ?? null;
            }
        }

        $providers = ModelCatalog::providers();
        if (empty($providers)) {
            $renderer->warning('No models loaded. The bundled resources/models.json may be missing.');
            return 1;
        }

        foreach ($providers as $provider) {
            if ($providerFilter !== null && $provider !== $providerFilter) {
                continue;
            }
            $models = ModelCatalog::modelsFor($provider);
            if (empty($models)) {
                continue;
            }
            $renderer->info(sprintf('%s (%d models)', $provider, count($models)));
            foreach ($models as $m) {
                $id = (string) ($m['id'] ?? '');
                $desc = $m['description'] ?? '';
                $input = $m['input'] ?? null;
                $output = $m['output'] ?? null;
                $price = ($input !== null && $output !== null)
                    ? sprintf('  [$%s / $%s per 1M]', $this->fmtPrice($input), $this->fmtPrice($output))
                    : '';
                $line = sprintf('    %s%s%s', $id, $desc !== '' ? "  — {$desc}" : '', $price);
                $renderer->line($line);
            }
            $renderer->newLine();
        }

        return 0;
    }

    private function update(Renderer $renderer, array $rest): int
    {
        $url = null;
        for ($i = 0; $i < count($rest); $i++) {
            if ($rest[$i] === '--url' || $rest[$i] === '-u') {
                $url = $rest[++$i] ?? null;
            }
        }
        $url = $url ?: ModelCatalog::remoteUrl();

        if ($url === null || $url === '') {
            $renderer->error('No remote URL configured.');
            $renderer->hint('Set SUPERAGENT_MODELS_URL, or run:  superagent models update --url <https://…/models.json>');
            return 2;
        }

        $renderer->info("Fetching model catalog from: {$url}");
        try {
            $count = ModelCatalog::refreshFromRemote($url);
        } catch (\Throwable $e) {
            $renderer->error('Update failed: ' . $e->getMessage());
            return 1;
        }

        $path = ModelCatalog::userOverridePath();
        $renderer->success("Saved {$count} models to: {$path}");
        $renderer->line('Run `superagent models list` to review.');
        return 0;
    }

    private function status(Renderer $renderer): int
    {
        $bundled = ModelCatalog::bundledPath();
        $override = ModelCatalog::userOverridePath();
        $url = ModelCatalog::remoteUrl();

        $renderer->info('Model catalog sources:');
        $renderer->line('  Bundled:  ' . $bundled . (is_readable($bundled) ? '  [ok]' : '  [missing]'));

        if (file_exists($override)) {
            $mtime = ModelCatalog::userOverrideMtime();
            $age = $mtime ? $this->humanAge(time() - $mtime) : 'unknown';
            $renderer->line('  Override: ' . $override . "  [updated {$age} ago]");
        } else {
            $renderer->line('  Override: ' . $override . '  [none]');
        }

        $renderer->line('  Remote:   ' . ($url ?? '(SUPERAGENT_MODELS_URL not set)'));
        $renderer->newLine();

        $providers = ModelCatalog::providers();
        $totals = array_map(fn ($p) => count(ModelCatalog::modelsFor($p)), $providers);
        $total = array_sum($totals);
        $renderer->info(sprintf(
            'Loaded %d models across %d providers: %s',
            $total,
            count($providers),
            implode(', ', $providers),
        ));

        return 0;
    }

    private function reset(Renderer $renderer): int
    {
        $path = ModelCatalog::userOverridePath();
        if (! file_exists($path)) {
            $renderer->info('No user override to reset.');
            return 0;
        }

        $confirm = $renderer->confirm("Delete {$path}?");
        if (! $confirm) {
            $renderer->info('Aborted.');
            return 0;
        }

        $ok = ModelCatalog::resetUserOverride();
        if ($ok) {
            $renderer->success('Override deleted. Bundled catalog will be used.');
            return 0;
        }
        $renderer->error("Failed to delete: {$path}");
        return 1;
    }

    private function usage(Renderer $renderer, string $sub): int
    {
        $renderer->error("Unknown models subcommand: {$sub}");
        $renderer->line('');
        $renderer->line('Usage:');
        $renderer->line('  superagent models list [--provider <p>]   List merged catalog');
        $renderer->line('  superagent models update [--url <u>]      Fetch remote catalog (SUPERAGENT_MODELS_URL)');
        $renderer->line('  superagent models refresh [<provider>]    Hit each provider\'s /models live');
        $renderer->line('  superagent models status                  Show source + last update');
        $renderer->line('  superagent models reset                   Remove user override');
        return 2;
    }

    private function fmtPrice(float|int $v): string
    {
        $v = (float) $v;
        if ($v === 0.0) {
            return '0';
        }
        if ($v < 0.1) {
            return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
        }
        if ($v < 1.0) {
            return rtrim(rtrim(sprintf('%.3f', $v), '0'), '.');
        }
        return rtrim(rtrim(sprintf('%.2f', $v), '0'), '.');
    }

    private function humanAge(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return floor($seconds / 60) . 'm';
        if ($seconds < 86400) return floor($seconds / 3600) . 'h';
        return floor($seconds / 86400) . 'd';
    }
}
