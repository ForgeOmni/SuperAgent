<?php

namespace SuperAgent\Tests\Unit\AdaptiveFeedback;

use PHPUnit\Framework\TestCase;
use SuperAgent\AdaptiveFeedback\CorrectionCategory;
use SuperAgent\AdaptiveFeedback\CorrectionPattern;
use SuperAgent\AdaptiveFeedback\CorrectionStore;

class CorrectionStoreTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'store_test_');
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    // ── Record ─────────────────────────────────────────────────────

    public function test_record_creates_new_pattern(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $pattern = $store->record(
            CorrectionCategory::TOOL_DENIED,
            'bash: rm -rf',
            'User denied',
            'Bash',
            'rm -rf /tmp',
        );

        $this->assertSame(1, $pattern->occurrences);
        $this->assertSame('bash: rm -rf', $pattern->pattern);
        $this->assertSame('Bash', $pattern->toolName);
        $this->assertCount(1, $store->getAll());
    }

    public function test_record_increments_existing_pattern(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $store->record(CorrectionCategory::TOOL_DENIED, 'bash: rm', 'reason1', 'Bash');
        $pattern = $store->record(CorrectionCategory::TOOL_DENIED, 'bash: rm', 'reason2', 'Bash');

        $this->assertSame(2, $pattern->occurrences);
        $this->assertCount(2, $pattern->reasons);
        $this->assertCount(1, $store->getAll());
    }

    public function test_record_deduplicates_reasons(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $store->record(CorrectionCategory::TOOL_DENIED, 'test', 'same reason');
        $pattern = $store->record(CorrectionCategory::TOOL_DENIED, 'test', 'same reason');

        $this->assertCount(1, $pattern->reasons);
    }

    // ── Get / Search ───────────────────────────────────────────────

    public function test_get_by_id(): void
    {
        $store = new CorrectionStore($this->tempFile);
        $pattern = $store->record(CorrectionCategory::TOOL_DENIED, 'test', 'reason');

        $found = $store->get($pattern->id);
        $this->assertNotNull($found);
        $this->assertSame('test', $found->pattern);

        $this->assertNull($store->get('nonexistent'));
    }

    public function test_get_by_category(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');
        $store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'b', 'r2');
        $store->record(CorrectionCategory::TOOL_DENIED, 'c', 'r3');

        $denied = $store->getByCategory(CorrectionCategory::TOOL_DENIED);
        $this->assertCount(2, $denied);

        $behavior = $store->getByCategory(CorrectionCategory::BEHAVIOR_CORRECTION);
        $this->assertCount(1, $behavior);
    }

    public function test_get_by_tool(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1', 'Bash');
        $store->record(CorrectionCategory::TOOL_DENIED, 'b', 'r2', 'Edit');
        $store->record(CorrectionCategory::TOOL_DENIED, 'c', 'r3', 'Bash');

        $bash = $store->getByTool('Bash');
        $this->assertCount(2, $bash);
    }

    public function test_search(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $store->record(CorrectionCategory::TOOL_DENIED, 'rm dangerous', 'r1');
        $store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'add docstrings', 'r2');

        $results = $store->search('dangerous');
        $this->assertCount(1, $results);
        $this->assertSame('rm dangerous', $results[0]->pattern);

        $results = $store->search('docstring');
        $this->assertCount(1, $results);
    }

    public function test_search_case_insensitive(): void
    {
        $store = new CorrectionStore($this->tempFile);
        $store->record(CorrectionCategory::TOOL_DENIED, 'Delete Files', 'r1');

        $results = $store->search('delete');
        $this->assertCount(1, $results);
    }

    // ── Promotable ─────────────────────────────────────────────────

    public function test_get_promotable(): void
    {
        $store = new CorrectionStore($this->tempFile);

        // Record 3 occurrences
        $store->record(CorrectionCategory::TOOL_DENIED, 'pattern-a', 'r1');
        $store->record(CorrectionCategory::TOOL_DENIED, 'pattern-a', 'r2');
        $store->record(CorrectionCategory::TOOL_DENIED, 'pattern-a', 'r3');

        // Only 1 occurrence
        $store->record(CorrectionCategory::TOOL_DENIED, 'pattern-b', 'r4');

        $promotable = $store->getPromotable(3);
        $this->assertCount(1, $promotable);
        $this->assertSame('pattern-a', $promotable[0]->pattern);
    }

    public function test_promoted_excluded_from_promotable(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $p = $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');
        $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r2');
        $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r3');

        $store->markPromoted($p->id, 'rule');

        $this->assertEmpty($store->getPromotable(3));
        $this->assertCount(1, $store->getPromoted());
    }

    // ── Delete / Clear ─────────────────────────────────────────────

    public function test_delete(): void
    {
        $store = new CorrectionStore($this->tempFile);
        $p = $store->record(CorrectionCategory::TOOL_DENIED, 'test', 'r1');

        $this->assertTrue($store->delete($p->id));
        $this->assertNull($store->get($p->id));
        $this->assertFalse($store->delete('nonexistent'));
    }

    public function test_clear(): void
    {
        $store = new CorrectionStore($this->tempFile);
        $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');
        $store->record(CorrectionCategory::TOOL_DENIED, 'b', 'r2');

        $count = $store->clear();
        $this->assertSame(2, $count);
        $this->assertEmpty($store->getAll());
    }

    // ── Persistence ────────────────────────────────────────────────

    public function test_persistence(): void
    {
        $store1 = new CorrectionStore($this->tempFile);
        $store1->record(CorrectionCategory::TOOL_DENIED, 'persistent', 'r1');
        unset($store1);

        $store2 = new CorrectionStore($this->tempFile);
        $all = $store2->getAll();
        $this->assertCount(1, $all);
        $this->assertSame('persistent', $all[0]->pattern);
    }

    // ── Import / Export ────────────────────────────────────────────

    public function test_export(): void
    {
        $store = new CorrectionStore($this->tempFile);
        $store->record(CorrectionCategory::TOOL_DENIED, 'export-test', 'r1');

        $exported = $store->export();

        $this->assertSame('1.0', $exported['version']);
        $this->assertArrayHasKey('exported_at', $exported);
        $this->assertCount(1, $exported['patterns']);
    }

    public function test_import(): void
    {
        $store = new CorrectionStore($this->tempFile);

        $data = [
            'patterns' => [
                [
                    'id' => 'abc123',
                    'category' => 'tool_denied',
                    'pattern' => 'imported pattern',
                    'tool_name' => 'Bash',
                    'occurrences' => 5,
                    'reasons' => ['imported'],
                    'first_seen_at' => '2026-01-01T00:00:00+00:00',
                    'last_seen_at' => '2026-04-01T00:00:00+00:00',
                ],
            ],
        ];

        $imported = $store->import($data);
        $this->assertSame(1, $imported);

        $pattern = $store->get('abc123');
        $this->assertNotNull($pattern);
        $this->assertSame(5, $pattern->occurrences);
    }

    public function test_import_merges_higher_count(): void
    {
        $store = new CorrectionStore($this->tempFile);

        // Existing pattern with 2 occurrences
        $store->record(CorrectionCategory::TOOL_DENIED, 'test', 'r1');
        $store->record(CorrectionCategory::TOOL_DENIED, 'test', 'r2');
        $existing = $store->getAll()[0];

        // Import same pattern with higher count
        $data = [
            'patterns' => [
                [
                    'id' => $existing->id,
                    'category' => 'tool_denied',
                    'pattern' => 'test',
                    'occurrences' => 10,
                    'reasons' => ['imported'],
                ],
            ],
        ];

        $imported = $store->import($data);
        $this->assertSame(1, $imported);

        $merged = $store->get($existing->id);
        $this->assertSame(10, $merged->occurrences);
    }

    // ── Statistics ─────────────────────────────────────────────────

    public function test_statistics(): void
    {
        $store = new CorrectionStore($this->tempFile);
        $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');
        $store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'b', 'r2');
        $store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r3'); // Same pattern

        $stats = $store->getStatistics();
        $this->assertSame(2, $stats['total_patterns']);
        $this->assertSame(3, $stats['total_corrections']);
        $this->assertArrayHasKey('tool_denied', $stats['by_category']);
    }

    // ── In-Memory Mode ─────────────────────────────────────────────

    public function test_in_memory_store(): void
    {
        $store = new CorrectionStore(null);
        $store->record(CorrectionCategory::TOOL_DENIED, 'memory-only', 'r1');

        $this->assertCount(1, $store->getAll());
    }
}
