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
 * Imports pi (earendil-works/pi) session logs.
 *
 * Layout: `~/.pi/agent/sessions/--<encoded-cwd>--/<timestamp>_<uuid>.jsonl`.
 * Spec: https://pi.dev/docs/latest/session-format
 *
 * Entry types we replay into SuperAgent's wire format:
 *   - session              (header, ignored — supplies metadata for listSessions)
 *   - message              (user / assistant — body in `message.content[]`)
 *   - branch_summary       (rendered as a system message preface)
 *   - compaction           (rendered as a system summary preface, replaces history)
 *
 * Skipped (do not affect replay):
 *   - model_change / thinking_level_change   (purely UX hints)
 *   - custom / custom_message / label / session_info
 *
 * Pi uses a tree (id/parentId) for entries; we project to the leaf-most
 * line of the current branch using the file's natural ordering (writes
 * are append-only, the leaf is the last entry whose chain reaches root).
 * For now we replay everything in file order — pi viewers render the
 * full file the same way unless /tree was used mid-session.
 */
final class PiImporter implements HarnessImporter
{
    private string $rootDir;

    public function __construct(?string $rootDir = null)
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        $this->rootDir = $rootDir ?? rtrim($home, '/\\') . '/.pi/agent/sessions';
    }

    public function harness(): string
    {
        return 'pi';
    }

    public function listSessions(int $limit = 50): array
    {
        if (!is_dir($this->rootDir)) return [];

        $rows = [];
        foreach (@scandir($this->rootDir) ?: [] as $projDir) {
            if ($projDir === '.' || $projDir === '..') continue;
            $abs = $this->rootDir . '/' . $projDir;
            if (!is_dir($abs)) continue;
            foreach (@glob($abs . '/*.jsonl') ?: [] as $file) {
                $row = $this->summarize($file, $projDir);
                if ($row !== null) $rows[] = $row;
            }
        }

        usort($rows, static fn($a, $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
        return array_slice($rows, 0, $limit);
    }

    public function load(string $idOrPath): array
    {
        $path = is_file($idOrPath) ? $idOrPath : null;
        if ($path === null) {
            // Best-effort lookup by id
            foreach ($this->listSessions(1000) as $row) {
                if ($row['id'] === $idOrPath) {
                    $path = $row['path'];
                    break;
                }
            }
        }
        if ($path === null || !is_file($path)) {
            throw new \RuntimeException("pi session not found: {$idOrPath}");
        }

        $messages = [];
        $handle = fopen($path, 'r');
        if (!$handle) throw new \RuntimeException("Cannot open pi session: {$path}");

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') continue;
                $entry = json_decode($line, true);
                if (!is_array($entry)) continue;

                $type = $entry['type'] ?? null;
                if ($type === 'message') {
                    $payload = $entry['message'] ?? [];
                    $msg = $this->parseMessage($payload);
                    if ($msg !== null) $messages[] = $msg;
                } elseif ($type === 'compaction' || $type === 'branch_summary') {
                    // Render as a system-style assistant message preface so
                    // the resumed conversation still has the summary context.
                    $summary = (string) ($entry['summary'] ?? '');
                    if ($summary !== '') {
                        $messages[] = new UserMessage(
                            [ContentBlock::text("[pi $type]\n" . $summary)],
                        );
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $messages;
    }

    private function parseMessage(array $payload): ?Message
    {
        $role = $payload['role'] ?? null;
        $content = $payload['content'] ?? [];
        if (!is_array($content)) $content = [];

        $blocks = [];
        foreach ($content as $block) {
            if (!is_array($block)) continue;
            $bt = $block['type'] ?? null;
            if ($bt === 'text' && isset($block['text'])) {
                $blocks[] = ContentBlock::text((string) $block['text']);
            } elseif ($bt === 'image' && !empty($block['data'])) {
                $blocks[] = ContentBlock::image(
                    (string) $block['data'],
                    (string) ($block['mimeType'] ?? 'image/png')
                );
            } elseif ($bt === 'thinking' && isset($block['thinking'])) {
                $blocks[] = ContentBlock::text((string) $block['thinking']);
            }
        }

        if ($blocks === []) return null;
        return $role === 'assistant'
            ? new AssistantMessage($blocks)
            : new UserMessage($blocks);
    }

    private function summarize(string $file, string $proj): ?array
    {
        $fh = @fopen($file, 'r');
        if (!$fh) return null;
        try {
            $header = null;
            $messageCount = 0;
            $firstUser = null;
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') continue;
                $entry = json_decode($line, true);
                if (!is_array($entry)) continue;
                if ($header === null && ($entry['type'] ?? null) === 'session') {
                    $header = $entry;
                    continue;
                }
                if (($entry['type'] ?? null) === 'message') {
                    $messageCount++;
                    if ($firstUser === null && ($entry['message']['role'] ?? null) === 'user') {
                        $firstUser = $this->firstText($entry['message']['content'] ?? []);
                    }
                }
            }
            return [
                'id' => basename($file, '.jsonl'),
                'path' => $file,
                'started_at' => $header['timestamp'] ?? null,
                'project' => $header['cwd'] ?? str_replace(['--', '_'], ['/', '/'], $proj),
                'message_count' => $messageCount,
                'first_user_message' => $firstUser,
            ];
        } finally {
            fclose($fh);
        }
    }

    private function firstText(array $content): ?string
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $text = (string) $block['text'];
                return mb_substr($text, 0, 160);
            }
        }
        return null;
    }
}
