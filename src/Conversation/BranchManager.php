<?php

declare(strict_types=1);

namespace SuperAgent\Conversation;

/**
 * Pi-style branch manager — entry-level operator for session trees.
 *
 * Pi (pi.dev/docs/latest/sessions) models a session as an append-only
 * file of entries where each entry has `id` + `parentId`. Together they
 * form a tree, not a line: forking creates an entry whose `parentId`
 * points at an earlier id. Switching branches via /tree triggers an
 * automatic summarization of the abandoned branch (BranchSummaryEntry).
 *
 * This class implements the tree algebra. It is **pure**: no I/O, no LLM
 * calls. Hosts supply entries as plain associative arrays and pass the
 * `summaryFn` closure to produce the textual summary of a collected
 * abandoned-branch slice.
 *
 * Usage:
 *
 *   $bm = new BranchManager($entries);
 *   $ancestor = $bm->findCommonAncestor($oldLeafId, $newLeafId);
 *   $abandoned = $bm->collectBranch($oldLeafId, $ancestor);
 *   $summaryText = $summaryFn($abandoned);
 *   $entry = $bm->makeBranchSummaryEntry($oldLeafId, $summaryText, $newLeafId);
 *
 * The host appends `$entry` to the JSONL file. Loading the file later
 * gives the reader enough context to skip rendering the abandoned path
 * but still display its summary.
 */
final class BranchManager
{
    /** @var array<string, array<string,mixed>> Indexed by id. */
    private array $byId = [];

    /** @var array<string, list<string>> parentId → child ids. */
    private array $children = [];

    /**
     * @param list<array<string,mixed>> $entries Session entries with
     *                                            'id' and 'parentId' keys.
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') continue;
            $this->byId[$id] = $entry;
            $parent = isset($entry['parentId']) ? (string) $entry['parentId'] : '';
            if ($parent !== '') {
                $this->children[$parent][] = $id;
            }
        }
    }

    /** @return list<string> Entry ids that have no descendants. */
    public function leaves(): array
    {
        $leaves = [];
        foreach (array_keys($this->byId) as $id) {
            if (!isset($this->children[$id]) || $this->children[$id] === []) {
                $leaves[] = $id;
            }
        }
        return $leaves;
    }

    /**
     * Walk from $id back to the root via parentId pointers. Returns the
     * sequence of entry ids root→leaf. Empty list if $id is unknown.
     *
     * @return list<string>
     */
    public function ancestry(string $id): array
    {
        if (!isset($this->byId[$id])) return [];
        $chain = [];
        $cursor = $id;
        $seen = [];
        while ($cursor !== '' && isset($this->byId[$cursor]) && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            array_unshift($chain, $cursor);
            $cursor = (string) ($this->byId[$cursor]['parentId'] ?? '');
        }
        return $chain;
    }

    /**
     * Closest common ancestor between two entry ids. Returns null when
     * either id is unknown or the two paths share no root.
     */
    public function findCommonAncestor(string $a, string $b): ?string
    {
        $chainA = $this->ancestry($a);
        if ($chainA === []) return null;
        $set = array_flip($chainA);
        $chainB = $this->ancestry($b);
        for ($i = count($chainB) - 1; $i >= 0; $i--) {
            if (isset($set[$chainB[$i]])) return $chainB[$i];
        }
        return null;
    }

    /**
     * Collect the entries between (exclusive of) $ancestor and $leaf —
     * i.e. the path "abandoned" when switching away from this branch.
     * Returns entries in chronological order (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function collectBranch(string $leaf, string $ancestor): array
    {
        $chain = $this->ancestry($leaf);
        $out = [];
        $past = false;
        foreach ($chain as $id) {
            if ($id === $ancestor) {
                $past = true;
                continue;
            }
            if ($past) {
                $out[] = $this->byId[$id];
            }
        }
        return $out;
    }

    /**
     * Build a Pi-format BranchSummaryEntry. Hosts append this to the
     * session file when the user switches away from a branch.
     *
     * @return array{
     *   type: string, id: string, parentId: string|null,
     *   timestamp: string, fromId: string, summary: string,
     *   details?: array<string,mixed>, fromHook: bool
     * }
     */
    public function makeBranchSummaryEntry(
        string $fromLeafId,
        string $summary,
        ?string $newParentId = null,
        ?array $details = null,
        bool $fromHook = false,
    ): array {
        $entry = [
            'type' => 'branch_summary',
            'id' => $this->shortId(),
            'parentId' => $newParentId,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'fromId' => $fromLeafId,
            'summary' => $summary,
            'fromHook' => $fromHook,
        ];
        if ($details !== null) {
            $entry['details'] = $details;
        }
        return $entry;
    }

    private function shortId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
