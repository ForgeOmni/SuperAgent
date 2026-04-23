<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Kimi;

/**
 * Moonshot's server-hosted `$web_fetch` builtin.
 *
 * Wire shape sent to Kimi:
 *   {"type": "builtin_function", "function": {"name": "$web_fetch"}}
 *
 * When the model decides to fetch a URL it emits this tool call
 * server-side; Moonshot's backend performs the HTTP request, extracts
 * readable content, and embeds the result directly into the assistant's
 * reply. No client-side execution, no tool_result round-trip.
 *
 * Pairs with `KimiMoonshotWebSearchTool`: search produces links, fetch
 * resolves the link body. Callers wiring both get a Kimi-native browse
 * pipeline without hosting any scraping infra.
 *
 * Unlike `$web_search`, `$web_fetch` is not yet documented in Moonshot's
 * public API reference — it's discoverable from kimi-cli's snapshot
 * tests. Treat availability as "most plans OK, your mileage may vary"
 * until Moonshot formalises it.
 */
class KimiMoonshotWebFetchTool extends KimiServerBuiltinTool
{
    protected function builtinName(): string
    {
        return '$web_fetch';
    }

    public function description(): string
    {
        return 'Fetch a URL via Moonshot\'s server-hosted browser. '
            . 'Extracted readable content is embedded directly in the '
            . 'assistant response — no explicit tool_result.';
    }
}
