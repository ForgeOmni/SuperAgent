<?php

namespace SuperAgent\Tests\Unit\AdaptiveFeedback;

use PHPUnit\Framework\TestCase;
use SuperAgent\AdaptiveFeedback\AdaptiveFeedbackEngine;
use SuperAgent\AdaptiveFeedback\CorrectionCategory;
use SuperAgent\AdaptiveFeedback\CorrectionCollector;
use SuperAgent\AdaptiveFeedback\CorrectionStore;
use SuperAgent\AdaptiveFeedback\FeedbackManager;

class FeedbackManagerTest extends TestCase
{
    private CorrectionStore $store;
    private FeedbackManager $manager;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->store = new CorrectionStore(null);
        $engine = new AdaptiveFeedbackEngine($this->store, promotionThreshold: 3);
        $collector = new CorrectionCollector($this->store);
        $this->manager = new FeedbackManager($this->store, $engine, $collector);

        $this->tempFile = tempnam(sys_get_temp_dir(), 'feedback_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    // ── List ───────────────────────────────────────────────────────

    public function test_list_all(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');
        $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'b', 'r2');

        $result = $this->manager->list();
        $this->assertSame(2, $result['total']);
        $this->assertSame(0, $result['promoted']);
        $this->assertSame(2, $result['pending']);
    }

    public function test_list_by_category(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');
        $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'b', 'r2');

        $result = $this->manager->list(CorrectionCategory::TOOL_DENIED);
        $this->assertSame(1, $result['total']);
    }

    public function test_list_with_search(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'remove files', 'r1');
        $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'add comments', 'r2');

        $result = $this->manager->list(search: 'remove');
        $this->assertSame(1, $result['total']);
    }

    public function test_list_sorted_by_occurrences(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'low', 'r1');
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'high', 'r1');
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'high', 'r2');
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'high', 'r3');

        $result = $this->manager->list();
        $this->assertSame('high', $result['patterns'][0]->pattern);
    }

    // ── Show ───────────────────────────────────────────────────────

    public function test_show(): void
    {
        $p = $this->store->record(CorrectionCategory::TOOL_DENIED, 'test', 'reason');

        $result = $this->manager->show($p->id);
        $this->assertNotNull($result);
        $this->assertSame('test', $result['pattern']->pattern);
        $this->assertArrayHasKey('promotable', $result);
    }

    public function test_show_not_found(): void
    {
        $this->assertNull($this->manager->show('nonexistent'));
    }

    // ── Delete / Clear ─────────────────────────────────────────────

    public function test_delete(): void
    {
        $p = $this->store->record(CorrectionCategory::TOOL_DENIED, 'test', 'r1');

        $this->assertTrue($this->manager->delete($p->id));
        $this->assertNull($this->manager->show($p->id));
    }

    public function test_clear(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'b', 'r2');

        $count = $this->manager->clear();
        $this->assertSame(2, $count);
        $this->assertSame(0, $this->manager->list()['total']);
    }

    // ── Import / Export ────────────────────────────────────────────

    public function test_export_and_import(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'export-test', 'r1');
        $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'behavior-test', 'r2');

        // Export
        $json = $this->manager->export();
        $decoded = json_decode($json, true);
        $this->assertCount(2, $decoded['patterns']);

        // Clear and import
        $this->manager->clear();
        $this->assertSame(0, $this->manager->list()['total']);

        $imported = $this->manager->import($json);
        $this->assertSame(2, $imported);
        $this->assertSame(2, $this->manager->list()['total']);
    }

    public function test_export_to_file(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'file-test', 'r1');

        $count = $this->manager->exportToFile($this->tempFile);
        $this->assertSame(1, $count);
        $this->assertFileExists($this->tempFile);
    }

    public function test_import_from_file(): void
    {
        // Create export file
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'file-import', 'r1');
        $this->manager->exportToFile($this->tempFile);
        $this->manager->clear();

        $count = $this->manager->importFromFile($this->tempFile);
        $this->assertSame(1, $count);
    }

    public function test_import_invalid_json(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->import('not json');
    }

    public function test_import_file_not_found(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->importFromFile('/nonexistent/path.json');
    }

    // ── Promote ────────────────────────────────────────────────────

    public function test_promote(): void
    {
        $p = $this->store->record(CorrectionCategory::BEHAVIOR_CORRECTION, 'test', 'r1');

        $result = $this->manager->promote($p->id);
        $this->assertNotNull($result);
        $this->assertTrue($result->isMemory());
    }

    public function test_auto_promote(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->store->record(CorrectionCategory::TOOL_DENIED, 'auto', "r{$i}", 'Bash');
        }

        $results = $this->manager->autoPromote();
        $this->assertCount(1, $results);
    }

    // ── Record Correction ──────────────────────────────────────────

    public function test_record_correction(): void
    {
        $pattern = $this->manager->recordCorrection('stop doing X');

        $this->assertSame('stop doing x', $pattern->pattern);
        $this->assertSame(1, $this->manager->list()['total']);
    }

    // ── Statistics ─────────────────────────────────────────────────

    public function test_statistics(): void
    {
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'a', 'r1');

        $stats = $this->manager->getStatistics();
        $this->assertArrayHasKey('total_patterns', $stats);
        $this->assertArrayHasKey('promotion_threshold', $stats);
        $this->assertArrayHasKey('auto_promote', $stats);
    }

    // ── Suggestions ────────────────────────────────────────────────

    public function test_suggestions(): void
    {
        // 2 out of 3 threshold
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'almost', 'r1');
        $this->store->record(CorrectionCategory::TOOL_DENIED, 'almost', 'r2');

        $suggestions = $this->manager->getSuggestions();
        $this->assertCount(1, $suggestions);
        $this->assertSame(1, $suggestions[0]['remaining']);
    }

    // ── Sub-component Access ───────────────────────────────────────

    public function test_sub_components_accessible(): void
    {
        $this->assertInstanceOf(CorrectionStore::class, $this->manager->getStore());
        $this->assertInstanceOf(AdaptiveFeedbackEngine::class, $this->manager->getEngine());
        $this->assertInstanceOf(CorrectionCollector::class, $this->manager->getCollector());
    }
}
