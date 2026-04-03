<?php

declare(strict_types=1);

namespace SuperAgent\Console\Commands;

use Illuminate\Console\Command;
use SuperAgent\CostCalculator;
use SuperAgent\SkillDistillation\DistillationManager;

/**
 * Artisan command for managing distilled skills.
 *
 * Usage:
 *   php artisan superagent:distill list [--search=keyword]
 *   php artisan superagent:distill show <id>
 *   php artisan superagent:distill delete <id>
 *   php artisan superagent:distill clear
 *   php artisan superagent:distill export [--output=path.json]
 *   php artisan superagent:distill import <path.json>
 *   php artisan superagent:distill stats
 */
class DistillCommand extends Command
{
    protected $signature = 'superagent:distill
                            {action : Action to perform (list, show, delete, clear, export, import, stats)}
                            {argument? : Skill ID or file path depending on action}
                            {--search= : Search skills by keyword}
                            {--output= : Output file path for export}
                            {--force : Skip confirmation for destructive actions}';

    protected $description = 'Manage distilled skills (auto-generated from successful agent executions)';

    public function handle(): int
    {
        $manager = app(DistillationManager::class);

        if ($manager === null) {
            $this->error('Skill distillation is not enabled. Set SUPERAGENT_SKILL_DISTILLATION_ENABLED=true');
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
            'stats' => $this->handleStats($manager),
            default => $this->handleUnknown($action),
        };
    }

    private function handleList(DistillationManager $manager): int
    {
        $result = $manager->list($this->option('search'));

        if (empty($result['skills'])) {
            $this->info('No distilled skills found.');
            return 0;
        }

        $rows = [];
        foreach ($result['skills'] as $skill) {
            $rows[] = [
                $skill->id,
                strlen($skill->name) > 35 ? substr($skill->name, 0, 32) . '...' : $skill->name,
                $skill->sourceModel,
                $skill->targetModel,
                $skill->sourceSteps,
                round($skill->estimatedSavingsPct) . '%',
                $skill->usageCount,
            ];
        }

        $this->table(['ID', 'Name', 'Source', 'Target', 'Steps', 'Savings', 'Used'], $rows);
        $this->info("Total: {$result['total']} distilled skills");

        return 0;
    }

    private function handleShow(DistillationManager $manager): int
    {
        $id = $this->argument('argument');
        if (!$id) {
            $this->error('Skill ID required: superagent:distill show <id>');
            return 1;
        }

        $skill = $manager->show($id);
        if ($skill === null) {
            $this->error("Skill not found: {$id}");
            return 1;
        }

        $this->info("Distilled Skill: {$skill->id}");
        $this->line("  Name:           {$skill->name}");
        $this->line("  Description:    {$skill->description}");
        $this->line("  Source model:   {$skill->sourceModel}");
        $this->line("  Target model:   {$skill->targetModel}");
        $this->line("  Steps:          {$skill->sourceSteps}");
        $this->line("  Source cost:    " . CostCalculator::format($skill->sourceCostUsd));
        $this->line("  Est. savings:   " . round($skill->estimatedSavingsPct) . '%');
        $this->line("  Used:           {$skill->usageCount} times");
        $this->line("  Tools:          " . implode(', ', $skill->requiredTools));
        $this->line("  Parameters:     " . implode(', ', $skill->parameters));
        $this->line("  Created:        {$skill->createdAt}");
        $this->line("  Last used:      " . ($skill->lastUsedAt ?? 'never'));

        $this->line('');
        $this->info('Generated Template:');
        $this->line($skill->template);

        return 0;
    }

    private function handleDelete(DistillationManager $manager): int
    {
        $id = $this->argument('argument');
        if (!$id) {
            $this->error('Skill ID required: superagent:distill delete <id>');
            return 1;
        }

        if (!$this->option('force') && !$this->confirm("Delete distilled skill {$id}?")) {
            return 0;
        }

        if ($manager->delete($id)) {
            $this->info("Skill {$id} deleted.");
            return 0;
        }

        $this->error("Skill not found: {$id}");
        return 1;
    }

    private function handleClear(DistillationManager $manager): int
    {
        if (!$this->option('force') && !$this->confirm('Clear ALL distilled skills?')) {
            return 0;
        }

        $count = $manager->clear();
        $this->info("Cleared {$count} distilled skills.");
        return 0;
    }

    private function handleExport(DistillationManager $manager): int
    {
        $path = $this->option('output') ?? $this->argument('argument') ?? 'distilled_skills_export.json';
        $count = $manager->exportToFile($path);
        $this->info("Exported {$count} skills to {$path}");
        return 0;
    }

    private function handleImport(DistillationManager $manager): int
    {
        $path = $this->argument('argument');
        if (!$path) {
            $this->error('File path required: superagent:distill import <path.json>');
            return 1;
        }

        try {
            $count = $manager->importFromFile($path);
            $this->info("Imported {$count} skills from {$path}");
            return 0;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function handleStats(DistillationManager $manager): int
    {
        $stats = $manager->getStatistics();

        $this->info('Skill Distillation Statistics:');
        $this->line("  Total skills:        {$stats['total_skills']}");
        $this->line("  Total distilled:     {$stats['total_distilled']}");
        $this->line("  Total usages:        {$stats['total_usages']}");
        $this->line("  Est. total savings:  " . CostCalculator::format($stats['estimated_total_savings_usd']));

        return 0;
    }

    private function handleUnknown(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Valid actions: list, show, delete, clear, export, import, stats');
        return 1;
    }
}
