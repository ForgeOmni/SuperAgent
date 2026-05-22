<?php

declare(strict_types=1);

namespace SuperAgent\Contracts;

/**
 * Opt-in capability marker — tools that implement this contract can produce
 * tabular results as Apache Arrow IPC stream bytes (typically passed through
 * `ToolResult::content` as base64 when the caller requested it).
 *
 * Wave 3 / SA-7. Keeps the core ToolInterface unchanged for backward
 * compatibility while letting bulk-data tools (ResearcherAgent's table
 * outputs, ExploreAgent file listings, financial fundamental fetchers)
 * skip the JSON round-trip when the downstream consumer is going straight
 * to Perspective / pyarrow / DuckDB.
 *
 * Convention:
 *   - When the caller passes `outputFormat: 'arrow'` in the tool input AND
 *     the tool implements this contract, the tool MUST emit Arrow bytes
 *     (base64-encoded inside ToolResult, with a `_format: 'arrow'` marker
 *     in the result envelope) OR fall back to JSON with a warning.
 *   - Tools that do not implement this contract ignore `outputFormat`.
 *
 * @see \SuperAgent\Arrow\ArrowSerializer  — pure-PHP IPC writer (JSON-columnar fast path)
 * @see \SuperAgent\Contracts\ToolInterface — base contract every tool implements
 */
interface SupportsArrowOutput
{
    /**
     * @return array<string,string> column → declared Arrow type
     *                              (e.g. ['ticker' => 'string', 'price' => 'float'])
     *                              An empty array means "infer from data".
     */
    public function declaredSchema(): array;

    /**
     * Execute and return rows ready for Arrow serialization.
     *
     * Tools should keep this method LIGHTWEIGHT — defer expensive work to a
     * separate phase if needed. The caller (typically AgentRunner) will
     * serialize the rows via `ArrowSerializer::fromRows()`.
     *
     * @param  array<string,mixed> $input
     * @return list<array<string,mixed>>
     */
    public function executeAsRows(array $input): array;
}
