<?php

declare(strict_types=1);

namespace SuperAgent\Squad;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses a YAML team definition into a `SquadPlan`.
 *
 * YAML schema (see `resources/squad-teams/*.yaml` for examples):
 *
 *   name: super-dev
 *   description: "Claude writes, Codex reviews, loop until approved"
 *   tier_map:
 *     hard:   {provider: anthropic, model: claude-opus-4-7}
 *     expert: {provider: openai,    model: gpt-5.1-codex}
 *   steps:
 *     - name: write
 *       prompt: "Implement {{task}}"
 *       difficulty: hard
 *       system: "You are a senior engineer."
 *     - name: review
 *       prompt: "Review {{steps.write.output}}. Approve or reject with feedback."
 *       difficulty: expert
 *       depends_on: [write]
 *       pause_after: true             # HITL approval gate after this step
 *     - name: synthesize
 *       prompt: "Final answer: {{steps.write.output}}"
 *       difficulty: moderate
 *       depends_on: [review]
 *   loops:
 *     - writer: write
 *       reviewer: review
 *       feedback_key: review.feedback
 *       max_retries: 3
 *
 * Field reference:
 *
 *   - `pause_after: true`  — equivalent to SubTask.requires_review=true;
 *                            wraps the step with an `ApprovalStep` so
 *                            the human gets a chance to redirect.
 *   - `parallel_group`     — when ≥2 steps share the same label they
 *                            execute through one `ParallelStep`.
 *   - `loops[]`            — declared separately from steps so a single
 *                            writer can be bound to multiple reviewers
 *                            (e.g. acceptance + Boss-view).
 *
 * Loader behaviour:
 *
 *   - Strict on missing required fields (`name`, `steps`, per-step
 *     `name`/`prompt`); silent (default) on unknown extras so future
 *     fields don't break older loaders.
 *   - `difficulty` defaults to `moderate` when omitted — same fallback
 *     `TaskDecomposer` uses, so YAML and heuristic plans behave
 *     consistently against `ModelTierMap::resolve()`.
 *   - Sanity checks: every `depends_on` target must exist; every
 *     loop's writer/reviewer must exist. Violations throw an
 *     `\InvalidArgumentException` at load time, NOT at run time, so
 *     CI catches typos before a paid model call happens.
 */
final class YamlSquadLoader
{
    /**
     * Load a team definition from a YAML file path. Throws when the
     * file is unreadable or the YAML is malformed.
     */
    public function loadFile(string $path): SquadPlan
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("YAML team file not readable: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException("Failed to read YAML team file: {$path}");
        }
        return $this->loadString($raw, $path);
    }

    /**
     * Load a team definition from a YAML string. `$source` is a
     * cosmetic label for error messages (typically the source path
     * when this was loaded via `loadFile()`).
     */
    public function loadString(string $yaml, string $source = '<string>'): SquadPlan
    {
        if (!class_exists(Yaml::class)) {
            throw new \RuntimeException(
                "YamlSquadLoader requires symfony/yaml. Run `composer require symfony/yaml`."
            );
        }
        try {
            $parsed = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException("Invalid YAML in {$source}: " . $e->getMessage(), 0, $e);
        }
        return $this->loadArray(is_array($parsed) ? $parsed : [], $source);
    }

    /**
     * Build a `SquadPlan` from an already-parsed associative array.
     *
     * @param array<string, mixed> $data
     */
    public function loadArray(array $data, string $source = '<array>'): SquadPlan
    {
        $name = isset($data['name']) ? (string) $data['name'] : '';
        if ($name === '') {
            throw new \InvalidArgumentException("Squad YAML in {$source} missing required 'name'");
        }
        $stepsRaw = $data['steps'] ?? [];
        if (!is_array($stepsRaw) || $stepsRaw === []) {
            throw new \InvalidArgumentException("Squad YAML in {$source} missing 'steps' (need at least one)");
        }

        $subtasks = [];
        $known = [];
        foreach ($stepsRaw as $i => $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException("Squad YAML in {$source}: steps[{$i}] must be a map");
            }
            $stepName = (string) ($raw['name'] ?? '');
            if ($stepName === '') {
                throw new \InvalidArgumentException("Squad YAML in {$source}: steps[{$i}] missing 'name'");
            }
            if (isset($known[$stepName])) {
                throw new \InvalidArgumentException("Squad YAML in {$source}: duplicate step name '{$stepName}'");
            }
            $known[$stepName] = true;

            $prompt = (string) ($raw['prompt'] ?? '');
            if ($prompt === '') {
                throw new \InvalidArgumentException(
                    "Squad YAML in {$source}: steps[{$i}] ('{$stepName}') missing 'prompt'"
                );
            }
            $difficulty = DifficultyClass::tryFrom((string) ($raw['difficulty'] ?? 'moderate'))
                ?? DifficultyClass::MODERATE;

            $subtasks[] = new SubTask(
                name:           $stepName,
                role:           (string) ($raw['role'] ?? $stepName),
                prompt:         $prompt,
                difficulty:     $difficulty,
                dependsOn:      array_values(array_map('strval', (array) ($raw['depends_on'] ?? []))),
                requiresReview: (bool) ($raw['pause_after'] ?? $raw['requires_review'] ?? false),
                systemPrompt:   isset($raw['system']) ? (string) $raw['system'] : (isset($raw['system_prompt']) ? (string) $raw['system_prompt'] : null),
                templateRef:    isset($raw['template_ref']) ? (string) $raw['template_ref'] : null,
                parallelGroup:  isset($raw['parallel_group']) ? (string) $raw['parallel_group'] : null,
                // Cross-mode YAML fields. Validated minimally here —
                // bad values surface at run time when ModeRouter
                // looks up the named mode, not at load time, so a
                // host that hasn't installed a custom router yet
                // still parses the file successfully.
                mode:           isset($raw['mode']) ? (string) $raw['mode'] : null,
                teamRef:        isset($raw['team']) ? (string) $raw['team'] : (isset($raw['team_ref']) ? (string) $raw['team_ref'] : null),
                modeChain:      isset($raw['mode_chain']) && is_array($raw['mode_chain'])
                                    ? array_values(array_map('strval', $raw['mode_chain']))
                                    : null,
                failCriteria:   isset($raw['fail_criteria']) ? (string) $raw['fail_criteria'] : null,
                parallelModes:  isset($raw['parallel_modes']) && is_array($raw['parallel_modes'])
                                    ? array_values($raw['parallel_modes'])
                                    : null,
                mergePrompt:    isset($raw['merge_prompt']) ? (string) $raw['merge_prompt'] : null,
            );
        }

        // Validate dependsOn references — typos here would silently
        // produce dangling edges in the pipeline graph.
        foreach ($subtasks as $st) {
            foreach ($st->dependsOn as $dep) {
                if (!isset($known[$dep])) {
                    throw new \InvalidArgumentException(
                        "Squad YAML in {$source}: step '{$st->name}' depends_on unknown step '{$dep}'"
                    );
                }
            }
        }

        // tier_map: band → {provider, model}
        $tierMap = [];
        foreach ((array) ($data['tier_map'] ?? []) as $band => $entry) {
            if (!is_array($entry)) continue;
            $provider = (string) ($entry['provider'] ?? '');
            $model    = (string) ($entry['model'] ?? '');
            if ($provider === '' || $model === '') continue;
            $tierMap[(string) $band] = ['provider' => $provider, 'model' => $model];
        }

        // loops[]: writer/reviewer/feedback_key/max_retries
        $loops = [];
        foreach ((array) ($data['loops'] ?? []) as $i => $raw) {
            if (!is_array($raw)) continue;
            $writer       = (string) ($raw['writer']       ?? '');
            $reviewer     = (string) ($raw['reviewer']     ?? '');
            $feedbackKey  = (string) ($raw['feedback_key'] ?? ($writer . '.feedback'));
            $maxRetries   = (int)    ($raw['max_retries']  ?? 3);
            if ($writer === '' || $reviewer === '') {
                throw new \InvalidArgumentException(
                    "Squad YAML in {$source}: loops[{$i}] missing writer or reviewer"
                );
            }
            if (!isset($known[$writer])) {
                throw new \InvalidArgumentException(
                    "Squad YAML in {$source}: loops[{$i}] writer '{$writer}' is not a declared step"
                );
            }
            if (!isset($known[$reviewer])) {
                throw new \InvalidArgumentException(
                    "Squad YAML in {$source}: loops[{$i}] reviewer '{$reviewer}' is not a declared step"
                );
            }
            $loops[] = new ReviewerLoopBinding($writer, $reviewer, $feedbackKey, max(1, $maxRetries));
        }

        return new SquadPlan(
            name:        $name,
            description: isset($data['description']) ? (string) $data['description'] : null,
            subTasks:    $subtasks,
            tierMap:     $tierMap,
            loops:       $loops,
            metadata:    (array) ($data['metadata'] ?? []),
        );
    }
}
