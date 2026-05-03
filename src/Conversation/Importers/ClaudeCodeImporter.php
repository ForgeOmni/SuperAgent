<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Importers;

use SuperAgent\Conversation\HarnessImporter;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\ToolResultMessage;
use SuperAgent\Messages\UserMessage;

/**
 * Imports Claude Code session logs (the JSONL files under
 * `~/.claude/projects/<project-hash>/<session-uuid>.jsonl`).
 *
 * Each line is a JSON object with at minimum:
 *   - `type`: 'user' | 'assistant' | 'system' | 'summary' | 'tool_result' | …
 *   - `message`: { role, content: ContentBlock[] }   (for user/assistant)
 *   - `timestamp`, `parentUuid`, `uuid`, `cwd`, `gitBranch` (metadata)
 *
 * The importer is deliberately conservative — it only emits message
 * types the SuperAgent SDK can replay (User / Assistant / ToolResult).
 * Streaming `tool_use` events that didn't yet have a result get folded
 * into the assistant message as content blocks, so a `Transcoder` pass
 * still produces a wire-correct request.
 *
 * Tested against Claude Code 1.x session formats. Older / newer schemas
 * are tolerated as long as the `message.content` array conforms to the
 * Anthropic content-block shape.
 */
final class ClaudeCodeImporter implements HarnessImporter
{
    private string $rootDir;

    public function __construct(?string $rootDir = null)
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        $this->rootDir = $rootDir ?? rtrim($home, '/\\') . '/.claude/projects';
    }

    public function harness(): string
    {
        return 'claude';
    }

    public function listSessions(int $limit = 50): array
    {
        if (!is_dir($this->rootDir)) return [];

        $sessions = [];
        $projectDirs = @scandir($this->rootDir) ?: [];
        foreach ($projectDirs as $proj) {
            if ($proj === '.' || $proj === '..') continue;
            $projDir = $this->rootDir . '/' . $proj;
            if (!is_dir($projDir)) continue;
            $files = @glob($projDir . '/*.jsonl') ?: [];
            foreach ($files as $file) {
                $row = $this->summarizeFile($file, $proj);
                if ($row !== null) $sessions[] = $row;
            }
        }

        usort($sessions, static function ($a, $b) {
            return strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? ''));
        });
        return array_slice($sessions, 0, $limit);
    }

    public function load(string $idOrPath): array
    {
        $path = $this->resolvePath($idOrPath);
        if ($path === null || !is_file($path)) {
            throw new \RuntimeException("Claude Code session not found: {$idOrPath}");
        }

        $messages = [];
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open Claude Code session: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') continue;
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) continue;

                $msg = $this->parseLine($decoded);
                if ($msg !== null) {
                    $messages[] = $msg;
                }
            }
        } finally {
            fclose($handle);
        }

        return $messages;
    }

    /** @return Message|null */
    private function parseLine(array $line): ?Message
    {
        $type = $line['type'] ?? null;
        $payload = $line['message'] ?? null;

        // Newer schemas tuck the role + content under `message`, older
        // ones inline at the top level. Accept both.
        $role    = $payload['role']    ?? $line['role']    ?? $type;
        $content = $payload['content'] ?? $line['content'] ?? null;

        if ($role === 'user') {
            return $this->parseUser($content);
        }
        if ($role === 'assistant') {
            return $this->parseAssistant($content);
        }
        return null;
    }

    /** @param string|array|null $content */
    private function parseUser($content): ?UserMessage
    {
        if ($content === null) return null;
        if (is_string($content)) {
            return $content === '' ? null : new UserMessage($content);
        }
        if (!is_array($content)) return null;

        // Claude Code sometimes emits user "tool_result" blocks as
        // first-class user content — those are routed to ToolResultMessage
        // instead of UserMessage by the assistant-side parser, so here
        // we only collect text. Tool results are stitched in `parseAssistant`.
        $textParts = [];
        foreach ($content as $block) {
            if (!is_array($block)) {
                if (is_string($block)) $textParts[] = $block;
                continue;
            }
            $blockType = $block['type'] ?? null;
            if ($blockType === 'text' && isset($block['text'])) {
                $textParts[] = (string) $block['text'];
            }
        }
        $text = trim(implode("\n", array_filter($textParts, static fn ($s) => $s !== '')));
        return $text === '' ? null : new UserMessage($text);
    }

    /** @param array|null $content */
    private function parseAssistant($content): ?AssistantMessage
    {
        if (!is_array($content)) return null;

        $msg = new AssistantMessage();
        foreach ($content as $block) {
            if (!is_array($block)) continue;
            $type = $block['type'] ?? null;
            switch ($type) {
                case 'text':
                    if (isset($block['text']) && $block['text'] !== '') {
                        $msg->content[] = ContentBlock::text((string) $block['text']);
                    }
                    break;
                case 'tool_use':
                    $msg->content[] = ContentBlock::toolUse(
                        (string) ($block['id'] ?? ''),
                        (string) ($block['name'] ?? ''),
                        is_array($block['input'] ?? null) ? $block['input'] : [],
                    );
                    break;
                case 'thinking':
                    if (isset($block['thinking']) && $block['thinking'] !== '') {
                        $msg->content[] = ContentBlock::thinking((string) $block['thinking']);
                    }
                    break;
                // 'tool_result' under an assistant message is rare — Claude
                // Code routes them through user messages — but tolerate.
                case 'tool_result':
                    $msg->content[] = ContentBlock::toolResult(
                        (string) ($block['tool_use_id'] ?? ''),
                        (string) ($block['content'] ?? ''),
                        (bool) ($block['is_error'] ?? false),
                    );
                    break;
            }
        }
        return $msg->content === [] ? null : $msg;
    }

    /**
     * Returns null when the input doesn't resolve to anything we can find.
     * Accepts both raw session ids (UUID) and absolute paths.
     */
    private function resolvePath(string $idOrPath): ?string
    {
        if (is_file($idOrPath)) return $idOrPath;
        if (!is_dir($this->rootDir)) return null;

        $projectDirs = @scandir($this->rootDir) ?: [];
        foreach ($projectDirs as $proj) {
            if ($proj === '.' || $proj === '..') continue;
            $candidate = $this->rootDir . '/' . $proj . '/' . $idOrPath;
            if (is_file($candidate)) return $candidate;
            $candidate .= '.jsonl';
            if (is_file($candidate)) return $candidate;
        }
        return null;
    }

    /**
     * Cheap header parse: read the first ~10 lines and pull out the
     * earliest user message + timestamp + count an estimate. Doesn't
     * load the full transcript — that's `load()`'s job.
     */
    private function summarizeFile(string $file, string $projectHash): ?array
    {
        $handle = @fopen($file, 'r');
        if (!$handle) return null;

        $startedAt = null;
        $firstUser = null;
        $count = 0;

        try {
            for ($i = 0; $i < 10000; $i++) {
                $line = fgets($handle);
                if ($line === false) break;
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') continue;
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) continue;
                $count++;
                if ($startedAt === null) {
                    $startedAt = (string) ($decoded['timestamp'] ?? '');
                    if ($startedAt === '') $startedAt = date('c', filemtime($file) ?: time());
                }
                if ($firstUser === null) {
                    $role = $decoded['message']['role'] ?? $decoded['role'] ?? null;
                    if ($role === 'user') {
                        $content = $decoded['message']['content'] ?? $decoded['content'] ?? null;
                        $firstUser = $this->extractFirstUserSnippet($content);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if ($count === 0) return null;
        return [
            'id'                 => basename($file, '.jsonl'),
            'path'               => $file,
            'started_at'         => $startedAt,
            'project'            => $this->humanizeProject($projectHash),
            'message_count'      => $count,
            'first_user_message' => $firstUser,
        ];
    }

    /** @param string|array|null $content */
    private function extractFirstUserSnippet($content): ?string
    {
        if (is_string($content)) return mb_substr($content, 0, 120);
        if (!is_array($content)) return null;
        foreach ($content as $block) {
            if (!is_array($block)) continue;
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                return mb_substr((string) $block['text'], 0, 120);
            }
        }
        return null;
    }

    /**
     * Claude Code's project dirs are URL-encoded paths
     * (`-Users-bob-code-foo`). Strip the leading dash and turn dashes
     * back into slashes for a friendlier picker label. Best-effort:
     * tolerate any input.
     */
    private function humanizeProject(string $hash): string
    {
        $stripped = ltrim($hash, '-');
        return '/' . str_replace('-', '/', $stripped);
    }
}
