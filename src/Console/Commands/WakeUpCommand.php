<?php

declare(strict_types=1);

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use SuperAgent\Memory\Palace\Hall;
use SuperAgent\Memory\Palace\PalaceFactory;

/**
 * superagent:wake-up
 *
 * Loads L0 (identity) + L1 (critical facts), and optionally a wing-scoped
 * room brief, and prints the result to stdout. Used to bootstrap external
 * AI sessions without full-memory loads.
 *
 *   php artisan superagent:wake-up
 *   php artisan superagent:wake-up --wing=wing_myproject
 *   php artisan superagent:wake-up --wing=wing_myproject --search="auth decisions"
 *   php artisan superagent:wake-up --stats
 */
class WakeUpCommand extends Command
{
    protected $signature = 'superagent:wake-up
                            {--wing= : Scope recall to a wing slug}
                            {--search= : After L0+L1, run a drawer search}
                            {--limit=5 : How many drawers to show when --search is used}
                            {--stats : Print palace stats only}';

    protected $description = 'Load lightweight L0+L1 memory context (MemPalace-style wake-up)';

    public function handle(): int
    {
        $config = config('superagent.palace', []);
        if (empty($config['enabled'])) {
            $this->error('Palace is not enabled. Set SUPERAGENT_PALACE_ENABLED=true');

            return 1;
        }

        $memoryPath = $this->resolveMemoryPath();
        $bundle = PalaceFactory::make($memoryPath, $config);

        if ($this->option('stats')) {
            $this->line(json_encode([
                'wings' => count($bundle->storage->listWings()),
                'rooms' => count($bundle->storage->listRooms()),
                'graph' => $bundle->graph->stats(),
                'vector_enabled' => $bundle->retriever->vectorEnabled(),
            ], JSON_PRETTY_PRINT));

            return 0;
        }

        $wing = $this->option('wing');
        $wake = $bundle->layers->wakeUp($wing);
        if ($wake === '') {
            $this->comment('No identity or critical facts saved yet.');
        } else {
            $this->line($wake);
        }

        $query = $this->option('search');
        if ($query !== null && $query !== '') {
            $hits = $bundle->retriever->search($query, (int) $this->option('limit'), [
                'wing' => $wing,
                'follow_tunnels' => true,
            ]);
            $this->newLine();
            $this->line('## Drawers matching: ' . $query);
            foreach ($hits as $hit) {
                $drawer = $hit['drawer'];
                $this->line(sprintf(
                    '- [%.2f] %s/%s/%s — %s',
                    $hit['score'],
                    $drawer->wingSlug,
                    $drawer->hall->value,
                    $drawer->roomSlug,
                    $this->preview($drawer->content, 140),
                ));
            }
            if (empty($hits)) {
                $this->comment('(no matching drawers)');
            }
        }

        return 0;
    }

    private function resolveMemoryPath(): string
    {
        $basePath = config('superagent.memory.base_path');
        if (is_string($basePath) && $basePath !== '') {
            return $basePath;
        }

        return storage_path('superagent/memory');
    }

    private function preview(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return strlen($text) <= $max ? $text : substr($text, 0, $max - 3) . '...';
    }
}
