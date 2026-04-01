<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Tool search with deferred tool loading ported from Claude Code.
 *
 * Two query modes:
 *  - "select:ToolA,ToolB" — Direct selection by exact name
 *  - "keyword query" — Fuzzy matching on names and descriptions
 *
 * Scoring system:
 *  - Exact name part match: 10 pts (12 for MCP tools)
 *  - Search hint match: 4 pts
 *  - Description match: 2 pts
 *
 * Returns tool_reference blocks that make deferred tools callable.
 */
class ToolSearchTool extends Tool
{
    /** @var array<string, array{name: string, description: string, deferred: bool}> */
    private static array $toolRegistry = [];

    /** @var array<string> Tool names that have been discovered (loaded via search) */
    private static array $discoveredTools = [];

    /** Scoring weights */
    private const SCORE_EXACT_NAME = 10;
    private const SCORE_MCP_NAME = 12;
    private const SCORE_HINT = 4;
    private const SCORE_DESCRIPTION = 2;

    /** Auto-enable threshold: defer when tool tokens > N% of context window */
    private const AUTO_THRESHOLD_PERCENT = 10;

    /** Maximum results per query */
    private const MAX_RESULTS = 5;

    public function name(): string
    {
        return 'ToolSearch';
    }

    public function description(): string
    {
        return 'Fetch full schema definitions for deferred tools so they can be called. Use "select:Name1,Name2" for direct selection, or keywords to search.';
    }

    public function category(): string
    {
        return 'tools';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Query to find tools. "select:<names>" for direct selection, or keywords to search.',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Maximum results (default 5).',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $query = trim($input['query'] ?? '');
        $maxResults = $input['max_results'] ?? self::MAX_RESULTS;

        if (empty($query)) {
            return ToolResult::error('Query is required.');
        }

        // Select mode: "select:ToolA,ToolB"
        if (str_starts_with($query, 'select:')) {
            $names = array_map('trim', explode(',', substr($query, 7)));
            return $this->selectTools($names);
        }

        // Keyword search mode
        return $this->searchTools($query, (int) $maxResults);
    }

    /**
     * Direct selection by exact tool names.
     */
    private function selectTools(array $names): ToolResult
    {
        $found = [];
        $notFound = [];

        foreach ($names as $name) {
            $tool = self::$toolRegistry[$name] ?? null;
            if ($tool !== null) {
                $found[] = $tool;
                self::markDiscovered($name);
            } else {
                // Case-insensitive fallback
                foreach (self::$toolRegistry as $regName => $regTool) {
                    if (strcasecmp($regName, $name) === 0) {
                        $found[] = $regTool;
                        self::markDiscovered($regName);
                        continue 2;
                    }
                }
                $notFound[] = $name;
            }
        }

        $result = ['matched' => count($found), 'tools' => $found];
        if (!empty($notFound)) {
            $result['not_found'] = $notFound;
        }

        return ToolResult::success($result);
    }

    /**
     * Fuzzy keyword search across tool names and descriptions.
     */
    private function searchTools(string $query, int $maxResults): ToolResult
    {
        $queryLower = strtolower($query);
        $queryWords = preg_split('/[\s_-]+/', $queryLower);
        $scored = [];

        foreach (self::$toolRegistry as $name => $tool) {
            $score = $this->scoreTool($tool, $queryLower, $queryWords);
            if ($score > 0) {
                $scored[] = ['tool' => $tool, 'score' => $score];
            }
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Take top results
        $results = array_slice($scored, 0, $maxResults);

        // Mark discovered
        foreach ($results as $r) {
            self::markDiscovered($r['tool']['name']);
        }

        return ToolResult::success([
            'matched' => count($results),
            'tools' => array_map(fn($r) => $r['tool'], $results),
        ]);
    }

    /**
     * Score a tool against a search query.
     */
    private function scoreTool(array $tool, string $queryLower, array $queryWords): int
    {
        $score = 0;
        $nameLower = strtolower($tool['name']);
        $descLower = strtolower($tool['description'] ?? '');
        $isMcp = str_starts_with($nameLower, 'mcp__');

        // Split tool name into parts (CamelCase, underscores, MCP segments)
        $nameParts = $this->splitToolName($nameLower);

        // Exact name part matches
        foreach ($queryWords as $word) {
            if (strlen($word) < 2) continue;

            foreach ($nameParts as $part) {
                if ($part === $word) {
                    $score += $isMcp ? self::SCORE_MCP_NAME : self::SCORE_EXACT_NAME;
                } elseif (str_contains($part, $word)) {
                    $score += (int) (($isMcp ? self::SCORE_MCP_NAME : self::SCORE_EXACT_NAME) * 0.6);
                }
            }

            // Search hint / category match
            if (isset($tool['search_hint']) && str_contains(strtolower($tool['search_hint']), $word)) {
                $score += self::SCORE_HINT;
            }

            // Description match
            if (str_contains($descLower, $word)) {
                $score += self::SCORE_DESCRIPTION;
            }
        }

        // Require name or query to be a prefix match for relevance
        if (str_contains($nameLower, $queryLower)) {
            $score += self::SCORE_EXACT_NAME;
        }

        return $score;
    }

    /**
     * Split a tool name into searchable parts.
     */
    private function splitToolName(string $name): array
    {
        // MCP format: mcp__server__action
        $parts = explode('__', $name);

        // Also split by underscore and CamelCase
        $allParts = [];
        foreach ($parts as $part) {
            // Split CamelCase
            $camelParts = preg_split('/(?=[A-Z])/', $part);
            foreach ($camelParts as $cp) {
                $cp = strtolower(trim($cp));
                if ($cp !== '') {
                    $allParts[] = $cp;
                }
            }
            // Also add underscore-split
            foreach (explode('_', $part) as $up) {
                $up = strtolower(trim($up));
                if ($up !== '' && !in_array($up, $allParts, true)) {
                    $allParts[] = $up;
                }
            }
        }

        return $allParts;
    }

    // ================================================================
    // Registry management (static, shared across instances)
    // ================================================================

    /**
     * Register a tool for search.
     */
    public static function registerTool(string $name, string $description, bool $deferred = false, ?string $searchHint = null): void
    {
        self::$toolRegistry[$name] = [
            'name' => $name,
            'description' => $description,
            'deferred' => $deferred,
            'search_hint' => $searchHint,
        ];
    }

    /**
     * Register multiple tools at once.
     */
    public static function registerTools(array $tools): void
    {
        foreach ($tools as $tool) {
            if (is_object($tool) && method_exists($tool, 'name')) {
                self::registerTool(
                    $tool->name(),
                    method_exists($tool, 'description') ? $tool->description() : '',
                    false,
                );
            } elseif (is_array($tool)) {
                self::registerTool(
                    $tool['name'] ?? '',
                    $tool['description'] ?? '',
                    $tool['deferred'] ?? false,
                    $tool['search_hint'] ?? null,
                );
            }
        }
    }

    /**
     * Mark a tool as discovered (loaded via search).
     */
    private static function markDiscovered(string $name): void
    {
        if (!in_array($name, self::$discoveredTools, true)) {
            self::$discoveredTools[] = $name;
        }
    }

    /**
     * Get list of discovered tool names.
     */
    public static function getDiscoveredTools(): array
    {
        return self::$discoveredTools;
    }

    /**
     * Check if a tool should be deferred based on auto-threshold.
     *
     * @param int $totalToolTokens  Estimated tokens for all tool definitions
     * @param int $contextWindow    Model's context window size
     */
    public static function shouldDeferTools(int $totalToolTokens, int $contextWindow): bool
    {
        $threshold = (int) ($contextWindow * self::AUTO_THRESHOLD_PERCENT / 100);
        return $totalToolTokens > $threshold;
    }

    /**
     * Check if a specific tool is deferred.
     */
    public static function isDeferredTool(string $name): bool
    {
        return (self::$toolRegistry[$name]['deferred'] ?? false) === true;
    }

    /**
     * Get deferred tools delta (added/removed since last check).
     */
    public static function getDeferredToolsDelta(array $previousNames): array
    {
        $currentNames = array_keys(array_filter(self::$toolRegistry, fn($t) => $t['deferred']));

        return [
            'added' => array_values(array_diff($currentNames, $previousNames)),
            'removed' => array_values(array_diff($previousNames, $currentNames)),
        ];
    }

    /**
     * Reset (for testing).
     */
    public static function reset(): void
    {
        self::$toolRegistry = [];
        self::$discoveredTools = [];
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}