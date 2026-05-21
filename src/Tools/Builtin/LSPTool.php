<?php

namespace SuperAgent\Tools\Builtin;

use SuperAgent\LSP\Manager;
use SuperAgent\Tools\Tool;
use SuperAgent\Tools\ToolResult;

/**
 * Surface LSP-driven code intelligence to the agent: pull diagnostics for the
 * file the model just touched, hover info for a position, and go-to-definition.
 *
 * Drives {@see Manager}, which lazily spawns language servers per project root
 * and keeps them warm across calls. Worktree defaults to the current working
 * directory but should be overridden in long-lived sessions.
 *
 * Actions:
 *   - `diagnostics` — `{path: string}` → list of LSP diagnostics by server id
 *   - `hover`       — `{path, line, character}` → hover info by server id
 *   - `definition`  — `{path, line, character}` → list of location entries
 *   - `touch`       — `{path, content?}` — push didOpen/didChange (no result)
 */
class LSPTool extends Tool
{
    private static ?Manager $manager = null;

    public function name(): string
    {
        return 'LSPTool';
    }

    public function description(): string
    {
        return 'Language Server Protocol operations: pull diagnostics for a file, hover info, or go-to-definition. Use after editing source files to surface type/syntax errors.';
    }

    public function category(): string
    {
        return 'lsp';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['diagnostics', 'hover', 'definition', 'touch'],
                    'description' => 'Operation to perform.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute path to the file.',
                ],
                'line' => [
                    'type' => 'integer',
                    'description' => 'Zero-based line number (hover/definition only).',
                ],
                'character' => [
                    'type' => 'integer',
                    'description' => 'Zero-based character offset on the line (hover/definition only).',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Optional in-memory content for touch (defaults to reading from disk).',
                ],
                'worktree' => [
                    'type' => 'string',
                    'description' => 'Absolute worktree root. Defaults to PHP cwd.',
                ],
            ],
            'required' => ['action', 'path'],
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $path = $input['path'] ?? '';
        if (! is_string($path) || $path === '') {
            return ToolResult::error('path is required');
        }

        $worktree = $input['worktree'] ?? getcwd() ?: '/';
        $manager = $this->manager((string) $worktree);

        try {
            switch ($action) {
                case 'diagnostics':
                    return ToolResult::success(['diagnostics' => $manager->diagnostics($path)]);
                case 'hover':
                    $line = (int) ($input['line'] ?? 0);
                    $char = (int) ($input['character'] ?? 0);
                    return ToolResult::success(['hover' => $manager->hover($path, $line, $char)]);
                case 'definition':
                    $line = (int) ($input['line'] ?? 0);
                    $char = (int) ($input['character'] ?? 0);
                    return ToolResult::success(['definitions' => $manager->definition($path, $line, $char)]);
                case 'touch':
                    $content = isset($input['content']) && is_string($input['content']) ? $input['content'] : null;
                    $manager->touchFile($path, $content);
                    return ToolResult::success(['ok' => true]);
                default:
                    return ToolResult::error("Unknown action: {$action}");
            }
        } catch (\Throwable $e) {
            return ToolResult::error('LSP failure: ' . $e->getMessage());
        }
    }

    /**
     * Process-scoped manager keyed by worktree; reused across tool calls so
     * we don't re-initialize servers between edits.
     */
    private function manager(string $worktree): Manager
    {
        if (self::$manager === null) {
            self::$manager = new Manager($worktree);
        }
        return self::$manager;
    }

    public static function resetManagerForTests(): void
    {
        if (self::$manager !== null) {
            self::$manager->shutdownAll();
            self::$manager = null;
        }
    }
}
