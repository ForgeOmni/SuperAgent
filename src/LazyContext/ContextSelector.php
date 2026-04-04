<?php

namespace SuperAgent\LazyContext;

/**
 * Selects which context fragments to load based on the current task and token budget.
 */
class ContextSelector
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_selected' => 10,
        ], $config);
    }

    /**
     * Select context fragment ids relevant to a given task.
     *
     * @param string $task     Description of the current task
     * @param array  $registry Context registry from LazyContextManager
     * @param array  $hints    Optional tag/type hints
     * @return string[]        Ordered list of context ids to load
     */
    public function selectForTask(string $task, array $registry, array $hints = []): array
    {
        $taskLower = strtolower($task);
        $scored = [];

        foreach ($registry as $id => $metadata) {
            $score = $metadata['priority'] ?? 5;

            // Boost by matching tags
            foreach ($metadata['tags'] ?? [] as $tag) {
                if (str_contains($taskLower, strtolower($tag))) {
                    $score += 3;
                }
            }

            // Boost by matching type hint
            if (!empty($hints['type']) && $metadata['type'] === $hints['type']) {
                $score += 2;
            }

            // Boost by matching explicit tag hints
            if (!empty($hints['tags'])) {
                foreach ($hints['tags'] as $hintTag) {
                    if (in_array($hintTag, $metadata['tags'] ?? [])) {
                        $score += 2;
                    }
                }
            }

            $scored[$id] = $score;
        }

        arsort($scored);

        $selected = array_keys(array_slice($scored, 0, $this->config['max_selected'], true));

        // Always include dependencies
        $withDeps = [];
        foreach ($selected as $id) {
            foreach ($registry[$id]['dependencies'] ?? [] as $dep) {
                if (!in_array($dep, $withDeps)) {
                    $withDeps[] = $dep;
                }
            }
            if (!in_array($id, $withDeps)) {
                $withDeps[] = $id;
            }
        }

        return $withDeps;
    }

    /**
     * Select context fragments fitting within a token budget, sorted by priority.
     *
     * @param array  $registry  Context registry
     * @param int    $maxTokens Token budget
     * @param string|null $focusArea Optional focus tag/type string
     * @return string[]
     */
    public function selectByTokenLimit(array $registry, int $maxTokens, ?string $focusArea = null): array
    {
        $scored = [];

        foreach ($registry as $id => $metadata) {
            $score = $metadata['priority'] ?? 5;

            if ($focusArea !== null) {
                if ($metadata['type'] === $focusArea || in_array($focusArea, $metadata['tags'] ?? [])) {
                    $score += 3;
                }
            }

            $scored[$id] = $score;
        }

        arsort($scored);

        $selected = [];
        $usedTokens = 0;

        foreach (array_keys($scored) as $id) {
            $size = $registry[$id]['size'] ?? 0;
            if ($usedTokens + $size > $maxTokens) {
                continue;
            }
            $selected[] = $id;
            $usedTokens += $size;
        }

        return $selected;
    }
}
