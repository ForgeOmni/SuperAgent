<?php

declare(strict_types=1);

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use SuperAgent\AdaptiveFeedback\CorrectionCategory;
use SuperAgent\AdaptiveFeedback\FeedbackManager;

/**
 * Artisan command for managing adaptive feedback patterns.
 *
 * Usage:
 *   php artisan superagent:feedback list [--category=tool_denied] [--search=keyword]
 *   php artisan superagent:feedback show <id>
 *   php artisan superagent:feedback delete <id>
 *   php artisan superagent:feedback clear
 *   php artisan superagent:feedback export [--output=path.json]
 *   php artisan superagent:feedback import <path.json>
 *   php artisan superagent:feedback promote <id>
 *   php artisan superagent:feedback stats
 */
class FeedbackCommand extends Command
{
    protected $signature = 'superagent:feedback
                            {action : Action to perform (list, show, delete, clear, export, import, promote, stats)}
                            {argument? : Pattern ID or file path depending on action}
                            {--category= : Filter by category (tool_denied, output_rejected, behavior_correction, edit_reverted, content_unwanted)}
                            {--search= : Search patterns by keyword}
                            {--output= : Output file path for export}
                            {--force : Skip confirmation for destructive actions}';

    protected $description = 'Manage adaptive feedback correction patterns';

    public function handle(): int
    {
        $manager = app(FeedbackManager::class);

        if ($manager === null) {
            $this->error('Adaptive feedback is not enabled. Set SUPERAGENT_ADAPTIVE_FEEDBACK_ENABLED=true');
            return 1;
        }

        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->handleList($manager),
            'show' => $this->handleShow($manager),
            'delete' => $this->handleDelete($manager),
            'clear' => $this->handleClear($manager),
            'export' => $this->handleExport($manager),
            'import' => $this->handleImport($manager),
            'promote' => $this->handlePromote($manager),
            'stats' => $this->handleStats($manager),
            default => $this->handleUnknown($action),
        };
    }

    private function handleList(FeedbackManager $manager): int
    {
        $category = null;
        if ($this->option('category')) {
            $category = CorrectionCategory::tryFrom($this->option('category'));
            if ($category === null) {
                $this->error("Invalid category. Valid: " . implode(', ', array_column(CorrectionCategory::cases(), 'value')));
                return 1;
            }
        }

        $result = $manager->list($category, $this->option('search'));

        if (empty($result['patterns'])) {
            $this->info('No correction patterns found.');
            return 0;
        }

        $rows = [];
        foreach ($result['patterns'] as $pattern) {
            $status = $pattern->promoted ? "-> {$pattern->promotedTo}" : 'pending';
            $rows[] = [
                $pattern->id,
                $pattern->category->value,
                strlen($pattern->pattern) > 50
                    ? substr($pattern->pattern, 0, 47) . '...'
                    : $pattern->pattern,
                $pattern->toolName ?? '-',
                $pattern->occurrences,
                $status,
            ];
        }

        $this->table(['ID', 'Category', 'Pattern', 'Tool', 'Count', 'Status'], $rows);
        $this->info("Total: {$result['total']} | Promoted: {$result['promoted']} | Pending: {$result['pending']}");

        return 0;
    }

    private function handleShow(FeedbackManager $manager): int
    {
        $id = $this->argument('argument');
        if (!$id) {
            $this->error('Pattern ID is required: superagent:feedback show <id>');
            return 1;
        }

        $result = $manager->show($id);
        if ($result === null) {
            $this->error("Pattern not found: {$id}");
            return 1;
        }

        $pattern = $result['pattern'];
        $this->info("Pattern: {$pattern->id}");
        $this->line("  Category:    {$pattern->category->value}");
        $this->line("  Pattern:     {$pattern->pattern}");
        $this->line("  Tool:        " . ($pattern->toolName ?? 'N/A'));
        $this->line("  Occurrences: {$pattern->occurrences}");
        $this->line("  First seen:  {$pattern->firstSeenAt}");
        $this->line("  Last seen:   {$pattern->lastSeenAt}");
        $this->line("  Promoted:    " . ($pattern->promoted ? "Yes ({$pattern->promotedTo})" : 'No'));
        $this->line("  Promotable:  " . ($result['promotable'] ? 'Yes' : 'No'));

        if (!empty($pattern->reasons)) {
            $this->line("  Reasons:");
            foreach ($pattern->reasons as $reason) {
                $this->line("    - {$reason}");
            }
        }

        return 0;
    }

    private function handleDelete(FeedbackManager $manager): int
    {
        $id = $this->argument('argument');
        if (!$id) {
            $this->error('Pattern ID is required: superagent:feedback delete <id>');
            return 1;
        }

        if (!$this->option('force') && !$this->confirm("Delete pattern {$id}?")) {
            return 0;
        }

        if ($manager->delete($id)) {
            $this->info("Pattern {$id} deleted.");
            return 0;
        }

        $this->error("Pattern not found: {$id}");
        return 1;
    }

    private function handleClear(FeedbackManager $manager): int
    {
        if (!$this->option('force') && !$this->confirm('Clear ALL correction patterns? This cannot be undone.')) {
            return 0;
        }

        $count = $manager->clear();
        $this->info("Cleared {$count} patterns.");
        return 0;
    }

    private function handleExport(FeedbackManager $manager): int
    {
        $path = $this->option('output') ?? $this->argument('argument') ?? 'feedback_export.json';

        $count = $manager->exportToFile($path);
        $this->info("Exported {$count} patterns to {$path}");
        return 0;
    }

    private function handleImport(FeedbackManager $manager): int
    {
        $path = $this->argument('argument');
        if (!$path) {
            $this->error('File path is required: superagent:feedback import <path.json>');
            return 1;
        }

        try {
            $count = $manager->importFromFile($path);
            $this->info("Imported {$count} patterns from {$path}");
            return 0;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function handlePromote(FeedbackManager $manager): int
    {
        $id = $this->argument('argument');
        if (!$id) {
            $this->error('Pattern ID is required: superagent:feedback promote <id>');
            return 1;
        }

        $result = $manager->promote($id);
        if ($result === null) {
            $this->error("Pattern not found, already promoted, or cannot be promoted: {$id}");
            return 1;
        }

        $this->info("Promoted pattern {$id} to {$result->type}:");
        $this->line($result->content);
        return 0;
    }

    private function handleStats(FeedbackManager $manager): int
    {
        $stats = $manager->getStatistics();

        $this->info('Adaptive Feedback Statistics:');
        $this->line("  Total patterns:      {$stats['total_patterns']}");
        $this->line("  Total corrections:   {$stats['total_corrections']}");
        $this->line("  Total promotions:    {$stats['total_promotions']}");
        $this->line("  Promotion threshold: {$stats['promotion_threshold']}");
        $this->line("  Auto-promote:        " . ($stats['auto_promote'] ? 'Yes' : 'No'));
        $this->line("  Promotable now:      {$stats['promotable_count']}");

        if (!empty($stats['by_category'])) {
            $this->line("  By category:");
            foreach ($stats['by_category'] as $cat => $count) {
                $this->line("    {$cat}: {$count}");
            }
        }

        // Show suggestions
        $suggestions = $manager->getSuggestions();
        if (!empty($suggestions)) {
            $this->line('');
            $this->info('Patterns approaching promotion:');
            foreach ($suggestions as $s) {
                $this->line("  [{$s['pattern']->id}] {$s['pattern']->pattern} — {$s['remaining']} more to promote");
            }
        }

        return 0;
    }

    private function handleUnknown(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Valid actions: list, show, delete, clear, export, import, promote, stats');
        return 1;
    }
}
