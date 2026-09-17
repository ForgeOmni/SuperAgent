<?php

declare(strict_types=1);

namespace SuperAgent\Tools;

use SuperAgent\Contracts\ToolInterface;

/**
 * What an agent is allowed to hold, independent of what it was handed.
 *
 * `allowed_tools` / `denied_tools` already gate *calls* by tool name. A policy
 * gates by what a tool *is* — its category, and whether it only reads — and it
 * is applied twice: when the tool list is assembled, and again immediately
 * before a call. The second check is the point. A tool can arrive after
 * assembly (a plugin, an MCP server's catalog, a builtin introduced by an SDK
 * upgrade, a host calling `addTool()` in a later code path), and a host that
 * embeds this SDK in its own product needs "this agent cannot run shell
 * commands" to be a property of the agent, not a fact about how carefully the
 * caller wrote its constructor arguments.
 *
 * A policy only ever narrows. It cannot grant a tool that was never handed to
 * the agent, and it does not replace the permission engine, the hooks or the
 * host's own authorization — those still run.
 *
 * @since 1.2.0
 */
final class ToolPolicy
{
    /**
     * Categories whose tools can reach the machine the process runs on, the
     * network, or the developer's working copy. This is the set an embedded
     * host almost always wants refused; it is deliberately a list of
     * categories rather than of class names, so a tool added later is covered
     * by what it declares itself to be.
     */
    public const HOST_CATEGORIES = [
        'execution',
        'file',
        'system',
        'terminal',
        'vcs',
        'browser',
        'network',
        'lsp',
        'debug',
        'testing',
        'code',
    ];

    /** @param list<string>|null $allowList @param list<string> $denyList @param list<string> $denyCategories */
    private function __construct(
        private readonly ?array $allowList,
        private readonly array $denyList,
        private readonly array $denyCategories,
        private readonly bool $readOnlyOnly,
    ) {
    }

    /**
     * @param array{allow_list?:list<string>|null, deny_list?:list<string>, deny_categories?:list<string>, read_only_only?:bool} $spec
     */
    public static function fromArray(array $spec): self
    {
        $allow = $spec['allow_list'] ?? null;

        return new self(
            $allow === null ? null : array_values(array_map('strval', $allow)),
            array_values(array_map('strval', $spec['deny_list'] ?? [])),
            array_values(array_map(
                static fn ($c): string => strtolower(trim((string) $c)),
                $spec['deny_categories'] ?? []
            )),
            (bool) ($spec['read_only_only'] ?? false),
        );
    }

    /** A policy that refuses every category in {@see HOST_CATEGORIES}. */
    public static function hostSafe(): self
    {
        return self::fromArray(['deny_categories' => self::HOST_CATEGORIES]);
    }

    /** True when the spec would not restrict anything, so no policy is worth building. */
    public static function isEmptySpec(array $spec): bool
    {
        return ($spec['allow_list'] ?? null) === null
            && empty($spec['deny_list'])
            && empty($spec['deny_categories'])
            && empty($spec['read_only_only']);
    }

    public function permits(ToolInterface $tool): bool
    {
        return $this->refusalReason($tool) === null;
    }

    /**
     * Why this tool is refused, or null when it is permitted. The text goes to
     * the model as the tool result, so it says what was refused and why
     * without naming anything the model could use to work around it.
     */
    public function refusalReason(ToolInterface $tool): ?string
    {
        $name = $tool->name();
        $resolved = ToolNameResolver::toSuperAgent($name);

        if (in_array($name, $this->denyList, true) || in_array($resolved, $this->denyList, true)) {
            return "tool '{$name}' is denied by the agent's tool policy";
        }

        if ($this->allowList !== null
            && ! in_array($name, $this->allowList, true)
            && ! in_array($resolved, $this->allowList, true)) {
            return "tool '{$name}' is not on the agent's allow list";
        }

        $category = strtolower(trim($this->categoryOf($tool)));
        if (in_array($category, $this->denyCategories, true)) {
            return "tools in category '{$category}' are denied by the agent's tool policy";
        }

        if ($this->readOnlyOnly && ! $this->isReadOnly($tool)) {
            return "tool '{$name}' is not read-only, and this agent may only use read-only tools";
        }

        return null;
    }

    /**
     * @param  iterable<ToolInterface> $tools
     * @return list<ToolInterface>
     */
    public function filter(iterable $tools): array
    {
        $kept = [];
        foreach ($tools as $tool) {
            if ($this->permits($tool)) {
                $kept[] = $tool;
            }
        }

        return $kept;
    }

    /** @return array{allow_list:list<string>|null, deny_list:list<string>, deny_categories:list<string>, read_only_only:bool} */
    public function toArray(): array
    {
        return [
            'allow_list' => $this->allowList,
            'deny_list' => $this->denyList,
            'deny_categories' => $this->denyCategories,
            'read_only_only' => $this->readOnlyOnly,
        ];
    }

    /**
     * `isReadOnly()` is part of ToolInterface, so every tool answers it.
     * `category()` is not — it lives on the abstract Tool base — and a host
     * may hand in its own implementation of the interface, so a tool that
     * cannot say what it is counts as 'general'. That is the permissive
     * reading, which is why the category deny list is a second line of
     * defence and not the only one.
     */
    private function categoryOf(ToolInterface $tool): string
    {
        return method_exists($tool, 'category') ? (string) $tool->category() : 'general';
    }

    private function isReadOnly(ToolInterface $tool): bool
    {
        return $tool->isReadOnly();
    }
}
