<?php

declare(strict_types=1);

namespace SuperAgent\SkillDistillation;

/**
 * High-level manager for the skill distillation system.
 *
 * Provides the API for listing, viewing, deleting, clearing,
 * and exporting distilled skills. Also handles the distillation
 * trigger from AgentResult.
 *
 * Commands:
 *   distill:list     — List all distilled skills
 *   distill:show     — Show details of a distilled skill
 *   distill:delete   — Delete a distilled skill by ID
 *   distill:clear    — Clear all distilled skills
 *   distill:export   — Export skills to a JSON file
 *   distill:import   — Import skills from a JSON file
 *   distill:stats    — Show distillation statistics
 */
class DistillationManager
{
    public function __construct(
        private readonly DistillationStore $store,
        private readonly DistillationEngine $engine,
    ) {}

    // ── Distillation ───────────────────────────────────────────────

    /**
     * Attempt to distill a skill from an execution trace.
     */
    public function distill(ExecutionTrace $trace, ?string $name = null): ?DistilledSkill
    {
        return $this->engine->distill($trace, $name);
    }

    /**
     * Check if a trace is worth distilling.
     */
    public function isWorthDistilling(ExecutionTrace $trace): bool
    {
        return $this->engine->isWorthDistilling($trace);
    }

    // ── List / View ────────────────────────────────────────────────

    /**
     * List all distilled skills.
     *
     * @return array{skills: DistilledSkill[], total: int}
     */
    public function list(?string $search = null): array
    {
        $skills = $search !== null
            ? $this->store->search($search)
            : $this->store->getAll();

        // Sort by usage count descending
        usort($skills, fn (DistilledSkill $a, DistilledSkill $b) => $b->usageCount <=> $a->usageCount);

        return [
            'skills' => $skills,
            'total' => count($skills),
        ];
    }

    /**
     * Show details of a distilled skill.
     */
    public function show(string $id): ?DistilledSkill
    {
        return $this->store->get($id);
    }

    // ── Delete / Clear ─────────────────────────────────────────────

    /**
     * Delete a skill by ID.
     */
    public function delete(string $id): bool
    {
        return $this->store->delete($id);
    }

    /**
     * Clear all skills.
     */
    public function clear(): int
    {
        return $this->store->clear();
    }

    // ── Usage Tracking ─────────────────────────────────────────────

    /**
     * Record that a distilled skill was used.
     */
    public function recordUsage(string $id): void
    {
        $this->store->recordUsage($id);
    }

    // ── Import / Export ────────────────────────────────────────────

    /**
     * Export all skills to JSON string.
     */
    public function export(): string
    {
        return json_encode(
            $this->store->export(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Export to file.
     */
    public function exportToFile(string $path): int
    {
        file_put_contents($path, $this->export());

        return count($this->store->getAll());
    }

    /**
     * Import from JSON string.
     */
    public function import(string $json): int
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON format');
        }

        return $this->store->import($data);
    }

    /**
     * Import from file.
     */
    public function importFromFile(string $path): int
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        return $this->import(file_get_contents($path));
    }

    // ── Statistics ─────────────────────────────────────────────────

    /**
     * Get comprehensive statistics.
     */
    public function getStatistics(): array
    {
        return $this->store->getStatistics();
    }

    // ── Sub-components ─────────────────────────────────────────────

    public function getStore(): DistillationStore
    {
        return $this->store;
    }

    public function getEngine(): DistillationEngine
    {
        return $this->engine;
    }
}
