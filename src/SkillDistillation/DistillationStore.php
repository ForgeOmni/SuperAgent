<?php

declare(strict_types=1);

namespace SuperAgent\SkillDistillation;

/**
 * Persistent storage for distilled skills.
 *
 * JSON file format:
 *   {
 *     "version": "1.0",
 *     "skills": { "id": {...}, ... },
 *     "stats": { "total_distilled": 5, "total_usages": 42 },
 *     "last_updated": "2026-04-03T..."
 *   }
 */
class DistillationStore
{
    /** @var array<string, DistilledSkill> */
    private array $skills = [];

    private int $totalDistilled = 0;

    private int $totalUsages = 0;

    public function __construct(private readonly ?string $storagePath = null)
    {
        $this->load();
    }

    /**
     * Save a distilled skill.
     */
    public function save(DistilledSkill $skill): void
    {
        $isNew = !isset($this->skills[$skill->id]);
        $this->skills[$skill->id] = $skill;

        if ($isNew) {
            $this->totalDistilled++;
        }

        $this->persist();
    }

    /**
     * Get a skill by ID.
     */
    public function get(string $id): ?DistilledSkill
    {
        return $this->skills[$id] ?? null;
    }

    /**
     * Find a skill by name.
     */
    public function findByName(string $name): ?DistilledSkill
    {
        foreach ($this->skills as $skill) {
            if ($skill->name === $name) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * Get all skills.
     *
     * @return DistilledSkill[]
     */
    public function getAll(): array
    {
        return array_values($this->skills);
    }

    /**
     * Search skills by keyword.
     *
     * @return DistilledSkill[]
     */
    public function search(string $keyword): array
    {
        $keyword = strtolower($keyword);

        return array_values(array_filter(
            $this->skills,
            fn (DistilledSkill $s) =>
                str_contains(strtolower($s->name), $keyword)
                || str_contains(strtolower($s->description), $keyword)
                || str_contains(strtolower(implode(' ', $s->requiredTools)), $keyword),
        ));
    }

    /**
     * Record a usage of a skill.
     */
    public function recordUsage(string $id): void
    {
        if (isset($this->skills[$id])) {
            $this->skills[$id]->recordUsage();
            $this->totalUsages++;
            $this->persist();
        }
    }

    /**
     * Delete a skill by ID.
     */
    public function delete(string $id): bool
    {
        if (!isset($this->skills[$id])) {
            return false;
        }

        unset($this->skills[$id]);
        $this->persist();

        return true;
    }

    /**
     * Clear all skills.
     */
    public function clear(): int
    {
        $count = count($this->skills);
        $this->skills = [];
        $this->totalDistilled = 0;
        $this->totalUsages = 0;
        $this->persist();

        return $count;
    }

    /**
     * Get statistics.
     */
    public function getStatistics(): array
    {
        $totalSavings = 0.0;
        foreach ($this->skills as $skill) {
            $totalSavings += $skill->sourceCostUsd * ($skill->estimatedSavingsPct / 100) * $skill->usageCount;
        }

        return [
            'total_skills' => count($this->skills),
            'total_distilled' => $this->totalDistilled,
            'total_usages' => $this->totalUsages,
            'estimated_total_savings_usd' => round($totalSavings, 4),
        ];
    }

    /**
     * Export all skills.
     */
    public function export(): array
    {
        return [
            'version' => '1.0',
            'exported_at' => date('c'),
            'skills' => array_map(fn (DistilledSkill $s) => $s->toArray(), $this->skills),
            'stats' => $this->getStatistics(),
        ];
    }

    /**
     * Import skills from exported data.
     *
     * @return int Number of skills imported
     */
    public function import(array $data): int
    {
        $imported = 0;

        foreach ($data['skills'] ?? [] as $skillData) {
            $skill = DistilledSkill::fromArray($skillData);

            if (!isset($this->skills[$skill->id])) {
                $this->skills[$skill->id] = $skill;
                $imported++;
            }
        }

        if ($imported > 0) {
            $this->persist();
        }

        return $imported;
    }

    private function load(): void
    {
        if ($this->storagePath === null || !file_exists($this->storagePath)) {
            return;
        }

        $contents = file_get_contents($this->storagePath);
        if ($contents === false) {
            return;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return;
        }

        foreach ($data['skills'] ?? [] as $id => $skillData) {
            $this->skills[$id] = DistilledSkill::fromArray($skillData);
        }

        $this->totalDistilled = (int) ($data['stats']['total_distilled'] ?? 0);
        $this->totalUsages = (int) ($data['stats']['total_usages'] ?? 0);
    }

    private function persist(): void
    {
        if ($this->storagePath === null) {
            return;
        }

        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->storagePath,
            json_encode([
                'version' => '1.0',
                'skills' => array_map(fn (DistilledSkill $s) => $s->toArray(), $this->skills),
                'stats' => [
                    'total_distilled' => $this->totalDistilled,
                    'total_usages' => $this->totalUsages,
                ],
                'last_updated' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }
}
