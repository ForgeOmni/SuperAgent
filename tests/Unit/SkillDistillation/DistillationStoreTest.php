<?php

namespace SuperAgent\Tests\Unit\SkillDistillation;

use PHPUnit\Framework\TestCase;
use SuperAgent\SkillDistillation\DistilledSkill;
use SuperAgent\SkillDistillation\DistillationStore;

class DistillationStoreTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'distill_test_');
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

    private function makeSkill(string $name = 'test-skill', string $id = ''): DistilledSkill
    {
        $id = $id ?: DistilledSkill::generateId($name);

        return new DistilledSkill(
            id: $id,
            name: $name,
            description: 'A test skill',
            category: 'distilled',
            sourceModel: 'claude-opus-4-20250514',
            targetModel: 'claude-sonnet-4-20250514',
            requiredTools: ['Read', 'Edit'],
            template: "---\nname: {$name}\n---\n# Template",
            parameters: ['target_file'],
            sourceSteps: 5,
            sourceCostUsd: 0.50,
            estimatedSavingsPct: 70.0,
            createdAt: date('c'),
        );
    }

    public function test_save_and_get(): void
    {
        $store = new DistillationStore(null);
        $skill = $this->makeSkill();
        $store->save($skill);

        $found = $store->get($skill->id);
        $this->assertNotNull($found);
        $this->assertSame('test-skill', $found->name);
    }

    public function test_find_by_name(): void
    {
        $store = new DistillationStore(null);
        $store->save($this->makeSkill('alpha'));
        $store->save($this->makeSkill('beta'));

        $this->assertNotNull($store->findByName('alpha'));
        $this->assertNull($store->findByName('gamma'));
    }

    public function test_get_all(): void
    {
        $store = new DistillationStore(null);
        $store->save($this->makeSkill('a'));
        $store->save($this->makeSkill('b'));

        $this->assertCount(2, $store->getAll());
    }

    public function test_search(): void
    {
        $store = new DistillationStore(null);
        $store->save($this->makeSkill('fix-login-bug'));
        $store->save($this->makeSkill('add-tests'));

        $results = $store->search('login');
        $this->assertCount(1, $results);
        $this->assertSame('fix-login-bug', $results[0]->name);
    }

    public function test_delete(): void
    {
        $store = new DistillationStore(null);
        $skill = $this->makeSkill();
        $store->save($skill);

        $this->assertTrue($store->delete($skill->id));
        $this->assertNull($store->get($skill->id));
        $this->assertFalse($store->delete('nonexistent'));
    }

    public function test_clear(): void
    {
        $store = new DistillationStore(null);
        $store->save($this->makeSkill('a'));
        $store->save($this->makeSkill('b'));

        $count = $store->clear();
        $this->assertSame(2, $count);
        $this->assertEmpty($store->getAll());
    }

    public function test_record_usage(): void
    {
        $store = new DistillationStore(null);
        $skill = $this->makeSkill();
        $store->save($skill);

        $store->recordUsage($skill->id);
        $store->recordUsage($skill->id);

        $found = $store->get($skill->id);
        $this->assertSame(2, $found->usageCount);
        $this->assertNotNull($found->lastUsedAt);
    }

    public function test_persistence(): void
    {
        $store1 = new DistillationStore($this->tempFile);
        $store1->save($this->makeSkill('persistent'));
        unset($store1);

        $store2 = new DistillationStore($this->tempFile);
        $this->assertCount(1, $store2->getAll());
        $this->assertSame('persistent', $store2->getAll()[0]->name);
    }

    public function test_export_and_import(): void
    {
        $store = new DistillationStore(null);
        $store->save($this->makeSkill('export-test'));

        $exported = $store->export();
        $this->assertSame('1.0', $exported['version']);
        $this->assertCount(1, $exported['skills']);

        // Import into new store
        $store2 = new DistillationStore(null);
        $imported = $store2->import($exported);
        $this->assertSame(1, $imported);
        $this->assertCount(1, $store2->getAll());
    }

    public function test_import_skips_duplicates(): void
    {
        $store = new DistillationStore(null);
        $skill = $this->makeSkill('dup');
        $store->save($skill);

        $data = $store->export();
        $imported = $store->import($data);
        $this->assertSame(0, $imported); // Already exists
    }

    public function test_statistics(): void
    {
        $store = new DistillationStore(null);
        $skill = $this->makeSkill();
        $store->save($skill);
        $store->recordUsage($skill->id);

        $stats = $store->getStatistics();
        $this->assertSame(1, $stats['total_skills']);
        $this->assertSame(1, $stats['total_distilled']);
        $this->assertSame(1, $stats['total_usages']);
        $this->assertGreaterThan(0, $stats['estimated_total_savings_usd']);
    }
}
