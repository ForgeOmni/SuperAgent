<?php

declare(strict_types=1);

namespace SuperAgent\SmartFlow;

use Symfony\Component\Yaml\Yaml;

/**
 * Reusable role/persona templates ("角色 roles — persona prompt 注入身份，可复用模板").
 * A persona bundles a system prompt with an optional default provider/model and
 * temperature, so a flow can say `['role' => 'reviewer']` instead of repeating a
 * paragraph of instructions and a model id at every call site.
 *
 * Personas are merged from three sources, later winning over earlier:
 *   1. built-in defaults below,
 *   2. `resources/flows/personas/*.yaml` (one persona per file, or a map),
 *   3. `config('superagent.smartflow.personas')`.
 *
 * Shape of a persona: `['system' => string, 'provider' => ?string,
 * 'model' => ?string, 'temperature' => ?float, 'description' => ?string]`.
 */
final class PersonaRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $personas;

    /** @param array<string, array<string, mixed>> $personas */
    public function __construct(array $personas = [])
    {
        $this->personas = array_merge(self::defaults(), $personas);
    }

    /**
     * Build a registry from the persona YAML directory + config overrides.
     */
    public static function load(?string $dir = null): self
    {
        $registry = new self();
        $dir ??= self::defaultDir();

        if ($dir !== null && is_dir($dir)) {
            foreach (glob(rtrim($dir, '/\\') . '/*.yaml') ?: [] as $file) {
                try {
                    $parsed = Yaml::parseFile($file);
                } catch (\Throwable) {
                    continue;
                }
                if (!is_array($parsed)) {
                    continue;
                }
                // A file may hold one persona (with an `id`) or a map of id => persona.
                if (isset($parsed['id'])) {
                    $registry->register((string) $parsed['id'], $parsed);
                } else {
                    foreach ($parsed as $id => $def) {
                        if (is_array($def)) {
                            $registry->register((string) $id, $def);
                        }
                    }
                }
            }
        }

        $fromConfig = Cfg::get('superagent.smartflow.personas', []);
        if (is_array($fromConfig)) {
            foreach ($fromConfig as $id => $def) {
                if (is_array($def)) {
                    $registry->register((string) $id, $def);
                }
            }
        }

        return $registry;
    }

    /** @param array<string, mixed> $def */
    public function register(string $id, array $def): void
    {
        $this->personas[$id] = array_merge($this->personas[$id] ?? [], $def);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return $this->personas[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->personas[$id]);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->personas;
    }

    private static function defaultDir(): ?string
    {
        // src/SmartFlow/.. /.. → project root → resources/flows/personas
        $candidate = dirname(__DIR__, 2) . '/resources/flows/personas';
        return is_dir($candidate) ? $candidate : null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function defaults(): array
    {
        return [
            'planner' => [
                'system' => 'You are a meticulous planner. Decompose the task into a small, ordered set of concrete steps. Be specific and avoid filler.',
                'description' => 'Breaks a goal into steps.',
            ],
            'builder' => [
                'system' => 'You are a senior implementer. Produce correct, idiomatic, runnable output. Prefer the smallest change that fully solves the task.',
                'description' => 'Implements / produces the artifact.',
            ],
            'reviewer' => [
                'system' => 'You are a sharp reviewer. Find real problems — correctness, edge cases, omissions. Be concrete and cite specifics. Do not invent issues.',
                'description' => 'Critically reviews an artifact.',
            ],
            'researcher' => [
                'system' => 'You are a careful researcher. Gather and organize relevant facts, note uncertainty, and distinguish evidence from inference.',
                'description' => 'Gathers and organizes information.',
            ],
            'writer' => [
                'system' => 'You are a clear, engaging writer. Match the requested tone and audience. Lead with the point; cut anything that does not earn its place.',
                'description' => 'Drafts prose / copy.',
            ],
            'critic' => [
                'system' => 'You are an adversarial critic. Try hard to refute the claim under review. Default to "not convincing" unless the evidence is strong.',
                'description' => 'Adversarial verifier for council/gates.',
            ],
            'chair' => [
                'system' => 'You are the chair. Synthesize the inputs into one decisive, well-justified verdict. Resolve disagreements explicitly.',
                'description' => 'Synthesizes multiple inputs into one verdict.',
            ],
        ];
    }
}
