<?php

declare(strict_types=1);

namespace SuperAgent\Tools\Providers\Glm;

use SuperAgent\Tools\Providers\ProviderToolBase;
use SuperAgent\Tools\ToolResult;

/**
 * Server-side web search via Z.AI / BigModel's dedicated endpoint.
 *
 * Z.AI lists Web Search as a standalone tool on their API — it's LLM-
 * optimised (the server summarises / re-ranks) and cheap. Wrapping it as
 * a SuperAgent Tool lets any main brain (Claude, GPT, Gemini…) search
 * the web without each vendor needing its own search integration.
 *
 * Endpoint: POST {base}/tools/web_search
 *   body: {"search_query": "...", "count": N, "search_engine": "..."}
 *
 * The request/response schema is documented at Z.AI's API reference but
 * the exact field names have churned across GLM-4.6 → GLM-5; the tool
 * accepts a couple of common shapes in the response and normalises them
 * to `{title, url, snippet}` triples. If a field is missing on a hit it's
 * dropped rather than raising — search is an imprecise signal anyway.
 */
class GlmWebSearchTool extends ProviderToolBase
{
    public function name(): string
    {
        return 'glm_web_search';
    }

    public function description(): string
    {
        return 'Search the public web via GLM\'s LLM-optimised search '
            . 'engine. Returns a list of hits with title, URL and snippet. '
            . 'Use when the main model needs current information beyond '
            . 'its training cutoff.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query.',
                ],
                'count' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of hits to return (default 10, max 50).',
                    'default' => 10,
                ],
                'engine' => [
                    'type' => 'string',
                    'description' => 'Optional search engine hint — "search_std", "search_pro", etc.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function attributes(): array
    {
        // Metered per query, touches public internet via GLM — not sensitive
        // (no user data uploaded beyond the query itself).
        return ['network', 'cost'];
    }

    public function execute(array $input): ToolResult
    {
        return $this->safeInvoke(function () use ($input) {
            $query = $input['query'] ?? null;
            if (! is_string($query) || trim($query) === '') {
                return ToolResult::error('query is required');
            }

            $body = ['search_query' => $query];
            if (isset($input['count'])) {
                $body['count'] = max(1, min(50, (int) $input['count']));
            }
            if (isset($input['engine']) && is_string($input['engine'])) {
                $body['search_engine'] = $input['engine'];
            }

            $response = $this->client()->post('tools/web_search', ['json' => $body]);
            $decoded = json_decode((string) $response->getBody(), true);

            return ToolResult::success([
                'query' => $query,
                'hits' => self::normaliseHits($decoded),
            ]);
        });
    }

    /**
     * Normalise GLM's response to `[{title, url, snippet}, ...]`.
     *
     * Accepts several shapes the endpoint has produced over GLM-4.6/5:
     *   - `{"search_result": [...]}`
     *   - `{"data": [{"results": [...]}]}`
     *   - `{"hits": [...]}`
     *
     * If the response doesn't match any of these, returns an empty array
     * rather than erroring — callers treat "no hits" as a valid outcome.
     *
     * @param mixed $response
     * @return array<int, array{title?: string, url?: string, snippet?: string}>
     */
    private static function normaliseHits($response): array
    {
        if (! is_array($response)) {
            return [];
        }

        $raw = $response['search_result']
            ?? $response['hits']
            ?? ($response['data'][0]['results'] ?? null)
            ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $hit = [];
            foreach (['title', 'url', 'link'] as $key) {
                if (isset($item[$key]) && is_string($item[$key])) {
                    $hit[$key === 'link' ? 'url' : $key] = $item[$key];
                }
            }
            foreach (['snippet', 'content', 'description'] as $key) {
                if (isset($item[$key]) && is_string($item[$key])) {
                    $hit['snippet'] = $item[$key];
                    break;
                }
            }
            if ($hit !== []) {
                $out[] = $hit;
            }
        }
        return $out;
    }
}
