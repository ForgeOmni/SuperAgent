<?php

declare(strict_types=1);

namespace SuperAgent\Conversation;

use SuperAgent\Messages\Message;

/**
 * Importers parse the per-harness session-file format that another
 * coding agent (Claude Code, Codex, OpenCode, pi, …) wrote to disk and
 * return a sequence of internal `Message` instances. Combined with
 * `Conversation\Transcoder`, the result feeds directly into a fresh
 * `Agent` running against any provider — i.e. you can pick up a Claude
 * Code conversation in SuperAgent on Kimi without losing the thread.
 *
 * Borrowed from jcode's session-resume capability. Each importer is
 * deliberately tolerant: malformed lines / unknown event types are
 * skipped silently rather than rejecting the whole session, because real
 * session logs from real harnesses are dirty.
 */
interface HarnessImporter
{
    /**
     * Stable identifier ('claude', 'codex', 'opencode', 'pi'). Used by
     * the resume CLI to pick the right importer without a pattern-match
     * over filename heuristics.
     */
    public function harness(): string;

    /**
     * Discover known sessions on the local machine, newest first.
     * Returns at most `$limit` rows. Each row carries enough info for
     * a "/resume" picker UI.
     *
     * @return list<array{
     *   id: string,
     *   path: string,
     *   started_at: string|null,
     *   project: string|null,
     *   message_count: int|null,
     *   first_user_message: string|null,
     * }>
     */
    public function listSessions(int $limit = 50): array;

    /**
     * Read a session file by id (the `id` field returned from
     * listSessions) or absolute path (paths take precedence so callers
     * can resume a manually-relocated file).
     *
     * Returns the parsed `Message[]` ready to feed into
     * `Agent::loadMessages($messages)` or to transcode through
     * `Transcoder::encode()` for a different provider's wire shape.
     *
     * Throws `\RuntimeException` when the file is missing or
     * structurally unparseable — callers should wrap in a try/catch.
     *
     * @return list<Message>
     */
    public function load(string $idOrPath): array;
}
