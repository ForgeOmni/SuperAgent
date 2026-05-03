<?php

declare(strict_types=1);

namespace SuperAgent\Conversation\Importers;

use SuperAgent\Conversation\HarnessImporter;
use SuperAgent\Messages\AssistantMessage;
use SuperAgent\Messages\ContentBlock;
use SuperAgent\Messages\Message;
use SuperAgent\Messages\UserMessage;

/**
 * Imports OpenAI Codex CLI session rollouts. Codex writes one JSONL
 * file per session under `~/.codex/sessions/rollout-<rollout-id>.jsonl`
 * (some versions use a YYYY-MM-DD subdirectory).
 *
 * Each line is a Responses-API-style event:
 *   - `{"type": "user_input", "input": "text"}`
 *   - `{"type": "assistant_response", "output": [{"type": "output_text",
 *      "text": "..."}, {"type": "function_call", "name": "...",
 *      "arguments": "..."}]}`
 *   - `{"type": "function_call_output", "call_id": "...", "output": "..."}`
 *
 * Schema has shifted across Codex CLI versions; this importer keeps a
 * forgiving line-by-line parser that only emits messages it recognises
 * and silently skips the rest.
 */
final class CodexImporter implements HarnessImporter
{
    private string $rootDir;

    public function __construct(?string $rootDir = null)
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        $this->rootDir = $rootDir ?? rtrim($home, '/\\') . '/.codex/sessions';
    }

    public function harness(): string
    {
        return 'codex';
    }

    public function listSessions(int $limit = 50): array
    {
        if (!is_dir($this->rootDir)) return [];

        $files = $this->findRollouts($this->rootDir);
        $rows = [];
        foreach ($files as $f) {
            $row = $this->summarizeFile($f);
            if ($row !== null) $rows[] = $row;
        }
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
        return array_slice($rows, 0, $limit);
    }

    public function load(string $idOrPath): array
    {
        $path = $this->resolvePath($idOrPath);
        if ($path === null || !is_file($path)) {
            throw new \RuntimeException("Codex session not found: {$idOrPath}");
        }

        $messages = [];
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open Codex session: {$path}");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') continue;
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) continue;
                $msg = $this->parseEvent($decoded);
                if ($msg !== null) {
                    $messages[] = $msg;
                }
            }
        } finally {
            fclose($handle);
        }
        return $messages;
    }

    private function parseEvent(array $event): ?Message
    {
        $type = $event['type'] ?? null;

        if ($type === 'user_input' || $type === 'user_message') {
            $text = (string) ($event['input'] ?? $event['text'] ?? '');
            return $text === '' ? null : new UserMessage($text);
        }

        if ($type === 'assistant_response' || $type === 'assistant_message' || $type === 'message') {
            $msg = new AssistantMessage();
            $output = $event['output'] ?? $event['content'] ?? [];
            if (!is_array($output)) return null;
            foreach ($output as $part) {
                if (!is_array($part)) continue;
                $partType = $part['type'] ?? null;
                if ($partType === 'output_text' || $partType === 'text') {
                    $text = (string) ($part['text'] ?? '');
                    if ($text !== '') $msg->content[] = ContentBlock::text($text);
                } elseif ($partType === 'function_call' || $partType === 'tool_call') {
                    $args = $part['arguments'] ?? null;
                    if (is_string($args)) {
                        $args = json_decode($args, true) ?: [];
                    }
                    $msg->content[] = ContentBlock::toolUse(
                        (string) ($part['call_id'] ?? $part['id'] ?? ''),
                        (string) ($part['name'] ?? ''),
                        is_array($args) ? $args : [],
                    );
                } elseif ($partType === 'reasoning' && isset($part['summary'])) {
                    // Responses-API encrypted reasoning: surface only the
                    // human-readable summary; the encrypted blob can't
                    // round-trip to non-OpenAI providers.
                    $summary = (string) $part['summary'];
                    if ($summary !== '') $msg->content[] = ContentBlock::thinking($summary);
                }
            }
            return $msg->content === [] ? null : $msg;
        }

        // function_call_output is a tool result that pairs with a previous
        // function_call by call_id. Emit it as a tool_result content block
        // appended to a synthetic user message — mirrors how Anthropic
        // wires user-side tool results.
        if ($type === 'function_call_output' || $type === 'tool_result') {
            $callId = (string) ($event['call_id'] ?? $event['tool_use_id'] ?? '');
            $output = (string) ($event['output'] ?? $event['content'] ?? '');
            $isError = (bool) ($event['is_error'] ?? false);
            return new UserMessage([
                ContentBlock::toolResult($callId, $output, $isError)->toArray(),
            ]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function findRollouts(string $dir): array
    {
        $out = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $dir,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iter as $f) {
            if (!$f->isFile()) continue;
            if (!str_ends_with($f->getFilename(), '.jsonl')) continue;
            $out[] = $f->getPathname();
            if (count($out) > 1000) break;
        }
        return $out;
    }

    private function summarizeFile(string $file): ?array
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
                if ($startedAt === null && isset($decoded['timestamp'])) {
                    $startedAt = (string) $decoded['timestamp'];
                }
                if ($firstUser === null && ($decoded['type'] ?? null) === 'user_input') {
                    $firstUser = mb_substr((string) ($decoded['input'] ?? ''), 0, 120);
                }
            }
        } finally {
            fclose($handle);
        }
        if ($count === 0) return null;
        if ($startedAt === null) $startedAt = date('c', filemtime($file) ?: time());
        return [
            'id'                 => basename($file, '.jsonl'),
            'path'               => $file,
            'started_at'         => $startedAt,
            'project'            => null,
            'message_count'      => $count,
            'first_user_message' => $firstUser,
        ];
    }

    private function resolvePath(string $idOrPath): ?string
    {
        if (is_file($idOrPath)) return $idOrPath;
        if (!is_dir($this->rootDir)) return null;
        $found = null;
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->rootDir,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iter as $f) {
            if (!$f->isFile()) continue;
            $name = $f->getFilename();
            if ($name === $idOrPath || $name === $idOrPath . '.jsonl') {
                $found = $f->getPathname();
                break;
            }
            if (basename($name, '.jsonl') === $idOrPath) {
                $found = $f->getPathname();
                break;
            }
        }
        return $found;
    }
}
