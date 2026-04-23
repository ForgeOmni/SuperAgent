<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Base class for Moonshot Kimi *server-hosted* builtin tools.
 *
 * Moonshot's chat API accepts a special tool type:
 *
 *   {"type": "builtin_function", "function": {"name": "$web_search"}}
 *
 * When the model calls one of these, Kimi executes it server-side and
 * returns the result directly in the assistant message — the client
 * never sees a schema, an input payload, or a round-trip tool_result.
 *
 * That means `inputSchema()` and `execute()` are never invoked when a
 * `$`-prefixed tool is passed to `KimiProvider`. We keep them on the
 * base contract (Tool's abstract methods) for symmetry, but hard-code
 * them to return empty / "not executed client-side" values: if a
 * caller wires a server builtin into a *different* provider (which
 * wouldn't understand the `$`-prefix), the resulting error message
 * points at the mismatch rather than a cryptic serialization crash.
 *
 * Subclasses only set the `$name` (must start with `$`) and optionally
 * override `description()` for UI affordances. See
 * `KimiMoonshotWebSearchTool` for a concrete sample.
 *
 * Reference: Moonshot's kimi-cli
 * (`packages/kosong/src/kosong/chat_provider/kimi.py:305-317`).
 */
abstract class KimiServerBuiltinTool extends Tool
{
    /**
     * Short name of the builtin. Subclasses declare a concrete value;
     * the `$`-prefix guard is enforced at construction time so typos
     * ("web_search" → not recognized as builtin) surface early.
     */
    abstract protected function builtinName(): string;

    public function name(): string
    {
        $n = $this->builtinName();
        if ($n === '' || $n[0] !== '$') {
            throw new \LogicException(
                static::class . '::builtinName() must start with `$` (got ' . var_export($n, true) . ')'
            );
        }
        return $n;
    }

    /**
     * Default — subclasses override for UI affordances. The description
     * is NOT sent to Moonshot's wire (builtin_function carries just the
     * name); it's purely for local tool listings / logs.
     */
    public function description(): string
    {
        return 'Moonshot Kimi server-hosted builtin: ' . $this->name()
            . '. Executes server-side; no client schema.';
    }

    /**
     * Server-hosted builtins have no client-facing schema. Anything
     * returned here is discarded by `KimiProvider::convertTools()`.
     */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => true];
    }

    /**
     * `execute()` is never called for `$`-prefixed tools when they flow
     * through `KimiProvider`. If another provider's tool-call loop
     * reaches here, surface the mismatch rather than silently no-op'ing
     * — a non-Kimi brain shouldn't be calling a `$web_search` tool.
     */
    public function execute(array $input): ToolResult
    {
        return ToolResult::failure(
            static::class . ' is a Moonshot server-hosted builtin and cannot be '
            . 'executed client-side. Ensure you routed this tool to KimiProvider.'
        );
    }

    /**
     * Security attribute: server-hosted builtins make network calls
     * server-side — declare it so ToolSecurityValidator can apply the
     * `SUPERAGENT_OFFLINE=1` gate. Subclasses can extend.
     */
    public function attributes(): array
    {
        return ['network'];
    }
}
