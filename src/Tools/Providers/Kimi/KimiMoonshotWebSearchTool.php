<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

/**
 * Moonshot's server-hosted `$web_search` builtin.
 *
 * Wire shape sent to Kimi:
 *   {"type": "builtin_function", "function": {"name": "$web_search"}}
 *
 * When the model calls this, Moonshot's backend performs the search
 * and embeds the results directly into the assistant's reply. We never
 * see a separate tool_result round-trip.
 *
 * This is the Moonshot-equivalent of GLM's `glm_web_search` / Anthropic's
 * `web_search_20250305`. Preferred when the main brain is already
 * `kimi` — one less hop than bolting on an external MCP search server.
 */
class KimiMoonshotWebSearchTool extends KimiServerBuiltinTool
{
    protected function builtinName(): string
    {
        return '$web_search';
    }

    public function description(): string
    {
        return 'Search the web via Moonshot\'s server-hosted web search. '
            . 'Results are embedded in the assistant response — no explicit tool_result.';
    }
}
