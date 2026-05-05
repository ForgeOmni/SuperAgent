<?php

declare(strict_types=1);

namespace SuperAgent\Memory;

use SuperAgent\Memory\Contracts\MemoryProviderInterface;
use SuperAgent\Security\UntrustedInput;

/**
 * Ad-hoc memory injection — codex's "ad-hoc instructions" pattern.
 *
 * The host pushes one-off memory entries (a fact the user just typed
 * in chat, a stale CI failure URL, a temporary policy override) and
 * the next turn sees them via `onTurnStart`. Entries are tagged with
 * a TTL so they auto-expire — sticky entries (TTL=0) survive the
 * whole session.
 *
 * What this is NOT:
 *
 *   - Not a replacement for `BuiltinMemoryProvider`. Run alongside.
 *   - Not persistent — entries die with the process. For durable
 *     memory, write to `BuiltinMemoryProvider` (MEMORY.md) instead.
 *   - Not searchable — `search()` always returns []. Ad-hoc memory
 *     is push-only; the model sees what the host injected, no more.
 *
 * Untrusted-input wrapping: any entry the host got from the user
 * (chat input, /remember command body) MUST be wrapped in
 * `<untrusted_*>` before injection. Pass `untrusted: true` on
 * `push()` and we'll wrap with `Security\UntrustedInput`. Trusted
 * developer-set entries (system overrides, programmatic injection)
 * pass `untrusted: false`.
 */
final class AdHocMemoryProvider implements MemoryProviderInterface
{
    /** @var array<int, array{content: string, expires_at: int|null, kind: string}> */
    private array $entries = [];

    public function getName(): string { return 'adhoc'; }

    public function initialize(array $config = []): void {}

    public function isReady(): bool { return true; }

    public function shutdown(): void
    {
        $this->entries = [];
    }

    /**
     * Push an entry. Returns the entry id so the host can remove it
     * later.
     *
     * @param string $content    plain text body
     * @param int    $ttlSeconds 0 = sticky for the session
     * @param bool   $untrusted  wrap in `<untrusted_note>` when true
     * @param string $kind       tag suffix when wrapping; lets the
     *                            model distinguish "user said" vs "ci
     *                            failure" vs "policy override"
     */
    public function push(
        string $content,
        int $ttlSeconds = 0,
        bool $untrusted = true,
        string $kind = 'note',
    ): int {
        $body = $untrusted ? UntrustedInput::wrap($content, $kind) : $content;
        $expiresAt = $ttlSeconds > 0 ? time() + $ttlSeconds : null;
        $this->entries[] = [
            'content'    => $body,
            'expires_at' => $expiresAt,
            'kind'       => $kind,
        ];
        return array_key_last($this->entries);
    }

    /**
     * Drop a single entry by id.
     */
    public function remove(int $id): bool
    {
        if (! isset($this->entries[$id])) return false;
        unset($this->entries[$id]);
        return true;
    }

    /**
     * Drop all entries.
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Currently-live entries (post-expiry filtering).
     *
     * @return array<int, array{content: string, expires_at: int|null, kind: string}>
     */
    public function active(): array
    {
        $now = time();
        $live = [];
        foreach ($this->entries as $id => $e) {
            if ($e['expires_at'] !== null && $e['expires_at'] <= $now) {
                continue;
            }
            $live[$id] = $e;
        }
        return $live;
    }

    public function onTurnStart(string $userMessage, array $conversationHistory): ?string
    {
        $live = $this->active();
        if ($live === []) return null;
        $blocks = [];
        foreach ($live as $entry) {
            $blocks[] = $entry['content'];
        }
        return implode("\n\n", $blocks);
    }

    public function onTurnEnd(array $assistantResponse, array $conversationHistory): void {}

    public function onPreCompress(array $messagesToCompress): void {}

    public function onSessionEnd(array $fullConversation): void
    {
        $this->clear();
    }

    public function onMemoryWrite(string $key, string $content, array $metadata = []): void {}

    public function search(string $query, int $maxResults = 5): array
    {
        // Ad-hoc memory is push-only; nothing to search through.
        return [];
    }
}
