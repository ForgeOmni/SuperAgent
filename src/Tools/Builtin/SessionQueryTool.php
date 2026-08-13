<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\Context\CompactionShadowStore;
use SuperAgent\Session\SessionManager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Model-facing retrieval over compaction-shadowed content and persisted
 * session history — deepseek-harness's session-query idea: compaction
 * becomes effectively lossless because summarized/truncated content stays
 * searchable and re-readable.
 *
 * Actions:
 *  - search: query shadowed compaction content (all sessions) and, when the
 *    SQLite FTS5 session backend is enabled, full-text search across saved
 *    session messages.
 *  - get: retrieve a shadowed entry's full content by id (chunked).
 */
class SessionQueryTool extends Tool
{
    private bool $sessionManagerResolved = false;

    public function __construct(
        private ?CompactionShadowStore $shadowStore = null,
        private ?SessionManager $sessionManager = null,
    ) {
        if ($this->sessionManager !== null) {
            $this->sessionManagerResolved = true;
        }
    }

    public function name(): string
    {
        return 'session_query';
    }

    public function description(): string
    {
        return 'Search and retrieve content that context compaction removed (shadowed tool outputs, '
            . 'pre-summary transcripts) and full-text search saved session history. '
            . 'Use action=search with a query, or action=get with an id from a '
            . '"session_query get id=..." hint or a search result.';
    }

    public function category(): string
    {
        return 'search';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['search', 'get'],
                    'description' => 'search: find shadowed/compacted content and session messages. get: read one shadowed entry by id.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query (required for action=search).',
                ],
                'id' => [
                    'type' => 'string',
                    'description' => 'Shadow entry id (required for action=get).',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Byte offset for action=get chunked reads (default 0).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results for search (default 10) or max bytes for get (default 20000).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'search' => $this->search($input),
            'get' => $this->get($input),
            default => ToolResult::error("Unknown action '{$action}'. Use 'search' or 'get'."),
        };
    }

    private function search(array $input): ToolResult
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('query is required for action=search.');
        }

        $limit = max(1, min((int) ($input['limit'] ?? 10), 50));
        $sections = [];

        $shadowMatches = $this->shadowStore()->search($query, $limit);
        if ($shadowMatches !== []) {
            $lines = ["## Shadowed compaction content (" . count($shadowMatches) . " matches)"];
            foreach ($shadowMatches as $m) {
                $tool = $m['tool_name'] ? " tool={$m['tool_name']}" : '';
                $lines[] = "- id={$m['id']} source={$m['source']}{$tool} bytes={$m['bytes']} time={$m['time']}\n"
                    . "  preview: " . str_replace("\n", ' ', (string) ($m['preview'] ?? ''));
            }
            $lines[] = 'Retrieve full content with action=get id=<id>.';
            $sections[] = implode("\n", $lines);
        }

        $manager = $this->resolveSessionManager();
        if ($manager !== null) {
            try {
                $sessionMatches = $manager->search($query, $limit);
            } catch (\Throwable $e) {
                $sessionMatches = [];
            }
            if ($sessionMatches !== []) {
                $lines = ["## Session history matches (" . count($sessionMatches) . ")"];
                foreach ($sessionMatches as $m) {
                    $lines[] = "- session={$m['session_id']} updated={$m['updated_at']}\n"
                        . "  snippet: " . str_replace("\n", ' ', (string) ($m['snippet'] ?? ''));
                }
                $sections[] = implode("\n", $lines);
            }
        }

        if ($sections === []) {
            return ToolResult::success("No matches for '{$query}' in shadowed content or session history.");
        }

        return ToolResult::success(implode("\n\n", $sections));
    }

    private function get(array $input): ToolResult
    {
        $id = trim((string) ($input['id'] ?? ''));
        if ($id === '') {
            return ToolResult::error('id is required for action=get.');
        }

        $entry = $this->shadowStore()->get(
            $id,
            (int) ($input['offset'] ?? 0),
            (int) ($input['limit'] ?? 20_000),
        );

        if ($entry === null) {
            return ToolResult::error("No shadowed entry found for id={$id}.");
        }

        $end = $entry['offset'] + strlen($entry['content']);
        $header = "shadow id={$id} source={$entry['source']}"
            . ($entry['tool_name'] ? " tool={$entry['tool_name']}" : '')
            . " bytes {$entry['offset']}-{$end} of {$entry['total']}";
        if ($end < $entry['total']) {
            $header .= " (call again with offset={$end} for more)";
        }

        return ToolResult::success($header . "\n" . $entry['content']);
    }

    private function shadowStore(): CompactionShadowStore
    {
        // Disabled-by-config still allows explicit injection (tests); the
        // fallback instance points at the default dir and simply finds
        // nothing when recording was off.
        return $this->shadowStore ??= (CompactionShadowStore::fromConfig()
            ?? new CompactionShadowStore(sys_get_temp_dir() . '/superagent-shadow-disabled'));
    }

    private function resolveSessionManager(): ?SessionManager
    {
        if (!$this->sessionManagerResolved) {
            $this->sessionManagerResolved = true;
            try {
                $this->sessionManager ??= SessionManager::fromConfig();
            } catch (\Throwable $e) {
                $this->sessionManager = null;
            }
        }

        return $this->sessionManager;
    }
}
