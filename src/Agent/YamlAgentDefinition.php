<?php

declare(strict_types=1);

namespace SuperAgent\Agent;

use SuperAgent\Tools\ToolNameResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * An AgentDefinition loaded from a YAML file.
 *
 * Motivating use case: Claude Code / Codex / kimi-cli have all converged
 * on YAML-as-agent-spec (see kimi-cli's `.agents/default/coder.yaml` for
 * a canonical example). Shipping YAML support alongside the existing
 * Markdown path lets our users pick whichever format they already have
 * tooling for — we don't force a migration either way.
 *
 * Supported top-level keys (matching kimi-cli where possible):
 *   name:             string   required
 *   description:      string
 *   system_prompt:    string   — inline body
 *   system_prompt_path: string — resolved relative to the YAML file
 *   allowed_tools:    string[] — canonical names resolved via ToolNameResolver
 *   disallowed_tools: string[]
 *   exclude_tools:    string[] — alias for disallowed_tools (kimi-cli name)
 *   read_only:        bool
 *   model:            string | "inherit"
 *   category:         string
 *   features:         map      — passed through to FeatureDispatcher
 *   extend:           string   — name of another agent spec to inherit from
 *                                (resolved by AgentSpecLoader before
 *                                instantiation — see that class for the
 *                                inheritance merge semantics)
 *
 * Every other key is preserved under `getMeta()` so custom metadata
 * survives round-trips through the registry.
 */
class YamlAgentDefinition extends AgentDefinition
{
    private array $spec;

    /**
     * @param array<string, mixed> $spec Already-inheritance-resolved spec.
     */
    public function __construct(array $spec)
    {
        if (empty($spec['name']) || !is_string($spec['name'])) {
            throw new \RuntimeException('YAML agent spec missing or non-string `name`');
        }
        $this->spec = $spec;
    }

    /**
     * Load a YAML file and instantiate. Does NOT resolve `extend:`
     * inheritance — callers that want inheritance should go through
     * `AgentSpecLoader::loadFile()` instead.
     */
    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("YAML agent file not readable: {$path}");
        }
        $spec = Yaml::parseFile($path) ?? [];
        if (!is_array($spec)) {
            throw new \RuntimeException("YAML agent file is not a map: {$path}");
        }
        // Resolve `system_prompt_path` relative to the YAML file.
        if (!empty($spec['system_prompt_path']) && is_string($spec['system_prompt_path'])) {
            $promptPath = $spec['system_prompt_path'];
            if ($promptPath[0] !== '/') {
                $promptPath = dirname($path) . '/' . $promptPath;
            }
            if (is_readable($promptPath)) {
                $spec['system_prompt'] = (string) file_get_contents($promptPath);
            }
        }
        return new self($spec);
    }

    public function name(): string
    {
        return (string) $this->spec['name'];
    }

    public function description(): string
    {
        return (string) ($this->spec['description'] ?? '');
    }

    public function systemPrompt(): ?string
    {
        $body = $this->spec['system_prompt'] ?? null;
        if (!is_string($body)) {
            return null;
        }
        $body = trim($body);
        return $body === '' ? null : $body;
    }

    public function allowedTools(): ?array
    {
        $tools = $this->spec['allowed_tools'] ?? null;
        return $tools !== null ? ToolNameResolver::resolveAll($tools) : null;
    }

    public function disallowedTools(): ?array
    {
        // `disallowed_tools` is our existing convention; `exclude_tools`
        // matches kimi-cli. Accept either — the two can even co-exist
        // (we union them).
        $a = $this->spec['disallowed_tools'] ?? null;
        $b = $this->spec['exclude_tools'] ?? null;
        if ($a === null && $b === null) {
            return null;
        }
        $merged = array_merge(
            is_array($a) ? $a : [],
            is_array($b) ? $b : [],
        );
        return $merged !== [] ? ToolNameResolver::resolveAll($merged) : null;
    }

    public function readOnly(): bool
    {
        return (bool) ($this->spec['read_only'] ?? false);
    }

    public function model(): ?string
    {
        $m = $this->spec['model'] ?? null;
        if ($m === null || $m === 'inherit') {
            return null;
        }
        return is_string($m) ? $m : null;
    }

    public function category(): string
    {
        return (string) ($this->spec['category'] ?? 'general');
    }

    public function features(): ?array
    {
        $f = $this->spec['features'] ?? null;
        return is_array($f) && $f !== [] ? $f : null;
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->spec[$key] ?? $default;
    }

    public function getAllMeta(): array
    {
        return $this->spec;
    }
}
