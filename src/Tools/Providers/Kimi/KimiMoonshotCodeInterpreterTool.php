<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

/**
 * Moonshot's server-hosted `$code_interpreter` builtin.
 *
 * Wire shape sent to Kimi:
 *   {"type": "builtin_function", "function": {"name": "$code_interpreter"}}
 *
 * Moonshot spins up a sandboxed Python runtime, executes code the model
 * emits, and inlines stdout / stderr / returned artifacts into the
 * assistant reply. Mirrors OpenAI's `code_interpreter` and Anthropic's
 * `code_execution_20250825` — one less hop than running a client-side
 * sandbox when the primary brain is already Kimi.
 *
 * As with other `$`-prefixed builtins the SDK never sees `execute()`
 * invocations: `KimiProvider::convertTools()` emits the builtin_function
 * envelope and the server handles everything end-to-end.
 *
 * Declares `network, cost, sensitive` attributes — code interpreter is
 * strictly more dangerous than `$web_search` since it runs arbitrary
 * code (with a sandbox gate we don't operate), so it trips the
 * ToolSecurityValidator when `SUPERAGENT_OFFLINE=1` or when the parent
 * agent is under a read-only permission mode.
 */
class KimiMoonshotCodeInterpreterTool extends KimiServerBuiltinTool
{
    protected function builtinName(): string
    {
        return '$code_interpreter';
    }

    public function description(): string
    {
        return 'Execute Python via Moonshot\'s server-hosted code interpreter. '
            . 'Stdout, stderr, and returned artifacts are embedded directly in '
            . 'the assistant response — no explicit tool_result.';
    }

    public function attributes(): array
    {
        // Parent declares `network`. Code interpreter additionally spends
        // provider quota per invocation (`cost`) and runs arbitrary code
        // in a sandbox we don't operate (`sensitive`).
        return ['network', 'cost', 'sensitive'];
    }
}
