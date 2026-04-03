<?php

declare(strict_types=1);

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use SuperAgent\Checkpoint\CheckpointManager;
use SuperAgent\CostCalculator;

/**
 * Artisan command for managing agent checkpoints.
 *
 * Usage:
 *   php artisan superagent:checkpoint list [--session=id]
 *   php artisan superagent:checkpoint show <id>
 *   php artisan superagent:checkpoint delete <id>
 *   php artisan superagent:checkpoint clear [--session=id]
 *   php artisan superagent:checkpoint prune [--keep=3]
 *   php artisan superagent:checkpoint stats
 */
class CheckpointCommand extends Command
{
    protected $signature = 'superagent:checkpoint
                            {action : Action to perform (list, show, delete, clear, prune, stats)}
                            {argument? : Checkpoint ID}
                            {--session= : Filter by session ID}
                            {--keep=3 : Number of checkpoints to keep per session (for prune)}
                            {--force : Skip confirmation for destructive actions}';

    protected $description = 'Manage agent checkpoints for crash recovery and task resumption';

    public function handle(): int
    {
        $manager = app(CheckpointManager::class);

        if ($manager === null) {
            $this->error('Checkpoint is not enabled. Set SUPERAGENT_CHECKPOINT_ENABLED=true');
            return 1;
        }

        return match ($this->argument('action')) {
            'list' => $this->handleList($manager),
            'show' => $this->handleShow($manager),
            'delete' => $this->handleDelete($manager),
            'clear' => $this->handleClear($manager),
            'prune' => $this->handlePrune($manager),
            'stats' => $this->handleStats($manager),
            default => $this->handleUnknown($this->argument('action')),
        };
    }

    private function handleList(CheckpointManager $manager): int
    {
        $checkpoints = $manager->list($this->option('session'));

        if (empty($checkpoints)) {
            $this->info('No checkpoints found.');
            return 0;
        }

        $rows = [];
        foreach ($checkpoints as $cp) {
            $promptPreview = strlen($cp->prompt) > 30
                ? substr($cp->prompt, 0, 27) . '...'
                : $cp->prompt;

            $rows[] = [
                $cp->id,
                substr($cp->sessionId, 0, 12),
                $cp->turnCount,
                CostCalculator::format($cp->totalCostUsd),
                $cp->model,
                $promptPreview,
                substr($cp->createdAt, 0, 19),
            ];
        }

        $this->table(['ID', 'Session', 'Turn', 'Cost', 'Model', 'Prompt', 'Created'], $rows);
        $this->info("Total: " . count($checkpoints) . " checkpoints");

        return 0;
    }

    private function handleShow(CheckpointManager $manager): int
    {
        $id = $this->argument('argument');
        if (!$id) {
            $this->error('Checkpoint ID required: superagent:checkpoint show <id>');
            return 1;
        }

        $cp = $manager->show($id);
        if ($cp === null) {
            $this->error("Checkpoint not found: {$id}");
            return 1;
        }

        $this->info("Checkpoint: {$cp->id}");
        $this->line("  Session:          {$cp->sessionId}");
        $this->line("  Turn:             {$cp->turnCount}");
        $this->line("  Cost:             " . CostCalculator::format($cp->totalCostUsd));
        $this->line("  Output tokens:    {$cp->turnOutputTokens}");
        $this->line("  Model:            {$cp->model}");
        $this->line("  Messages:         " . count($cp->messages));
        $this->line("  Prompt:           {$cp->prompt}");
        $this->line("  Created:          {$cp->createdAt}");

        return 0;
    }

    private function handleDelete(CheckpointManager $manager): int
    {
        $id = $this->argument('argument');
        if (!$id) {
            $this->error('Checkpoint ID required');
            return 1;
        }

        if (!$this->option('force') && !$this->confirm("Delete checkpoint {$id}?")) {
            return 0;
        }

        if ($manager->delete($id)) {
            $this->info("Checkpoint {$id} deleted.");
            return 0;
        }

        $this->error("Checkpoint not found: {$id}");
        return 1;
    }

    private function handleClear(CheckpointManager $manager): int
    {
        $session = $this->option('session');
        $scope = $session ? "session {$session}" : 'ALL sessions';

        if (!$this->option('force') && !$this->confirm("Clear checkpoints for {$scope}?")) {
            return 0;
        }

        $count = $manager->clear($session);
        $this->info("Cleared {$count} checkpoints.");
        return 0;
    }

    private function handlePrune(CheckpointManager $manager): int
    {
        $keep = (int) $this->option('keep');
        $pruned = $manager->prune($keep);
        $this->info("Pruned {$pruned} old checkpoints (keeping {$keep} per session).");
        return 0;
    }

    private function handleStats(CheckpointManager $manager): int
    {
        $stats = $manager->getStatistics();

        $this->info('Checkpoint Statistics:');
        $this->line("  Total checkpoints:  {$stats['total_checkpoints']}");
        $this->line("  Total sessions:     {$stats['total_sessions']}");
        $this->line("  Total size:         " . $this->formatBytes($stats['total_size_bytes']));
        $this->line("  Interval:           every {$manager->getInterval()} turns");
        $this->line("  Enabled:            " . ($manager->isEnabled() ? 'Yes' : 'No'));

        return 0;
    }

    private function handleUnknown(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Valid actions: list, show, delete, clear, prune, stats');
        return 1;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}
