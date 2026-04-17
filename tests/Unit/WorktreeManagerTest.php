<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Swarm\WorktreeManager;
use SuperAgent\Swarm\WorktreeInfo;

class WorktreeManagerTest extends TestCase
{
    private string $tmpBaseDir;
    private ?string $tmpRepoDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBaseDir = sys_get_temp_dir() . '/sa_wt_test_' . uniqid();
        mkdir($this->tmpBaseDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up worktrees first (git worktree remove)
        if ($this->tmpRepoDir !== null) {
            exec(sprintf('cd %s && git worktree prune 2>/dev/null', escapeshellarg($this->tmpRepoDir)));
        }
        $this->recursiveDelete($this->tmpBaseDir);
        if ($this->tmpRepoDir !== null) {
            $this->recursiveDelete($this->tmpRepoDir);
        }
        parent::tearDown();
    }

    private function createTempGitRepo(): string
    {
        $dir = sys_get_temp_dir() . '/sa_wt_repo_' . uniqid();
        mkdir($dir, 0755, true);
        exec(sprintf('cd %s && git init && git commit --allow-empty -m "init" 2>&1', escapeshellarg($dir)));
        $this->tmpRepoDir = $dir;
        return $dir;
    }

    // ── Construction ──────────────────────────────────────────────

    public function testCreation(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);
        $this->assertEquals($this->tmpBaseDir, $manager->getBaseDir());
        $this->assertDirectoryExists($this->tmpBaseDir);
    }

    public function testCreationCreatesBaseDir(): void
    {
        $dir = $this->tmpBaseDir . '/nested/deep';
        $manager = new WorktreeManager(baseDir: $dir);
        $this->assertDirectoryExists($dir);
    }

    public function testGetSymlinkDirs(): void
    {
        $manager = new WorktreeManager(
            baseDir: $this->tmpBaseDir,
            symlinkDirs: ['node_modules', 'vendor'],
        );
        $this->assertEquals(['node_modules', 'vendor'], $manager->getSymlinkDirs());
    }

    // ── Slug sanitization ─────────────────────────────────────────

    public function testSanitizeSlugBasic(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);
        $this->assertEquals('hello_world', $manager->sanitizeSlug('hello world'));
        $this->assertEquals('agent-123', $manager->sanitizeSlug('agent-123'));
        $this->assertEquals('test.foo', $manager->sanitizeSlug('test.foo'));
    }

    public function testSanitizeSlugSpecialChars(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);
        // Dots and hyphens are allowed, slashes become underscores
        $this->assertEquals('.._.._.._etc_passwd', $manager->sanitizeSlug('../../../etc/passwd'));
        $this->assertEquals('hello__world', $manager->sanitizeSlug('hello@#world'));
    }

    public function testSanitizeSlugTruncation(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir, maxSlugLength: 10);
        $result = $manager->sanitizeSlug('very_long_slug_that_exceeds_limit');
        $this->assertEquals(10, strlen($result));
    }

    // ── fromConfig override pattern ─────────────────────────────────

    public function testFromConfigOverrideEnabled(): void
    {
        $manager = WorktreeManager::fromConfig(overrides: [
            'enabled' => true,
            'base_dir' => $this->tmpBaseDir . '/override',
        ]);

        $this->assertNotNull($manager);
        $this->assertEquals($this->tmpBaseDir . '/override', $manager->getBaseDir());
    }

    public function testFromConfigReturnsNullWhenOverrideDisabled(): void
    {
        $manager = WorktreeManager::fromConfig(overrides: ['enabled' => false]);
        $this->assertNull($manager);
    }

    public function testFromConfigOverrideSymlinkDirs(): void
    {
        $manager = WorktreeManager::fromConfig(overrides: [
            'enabled' => true,
            'base_dir' => $this->tmpBaseDir,
            'symlink_dirs' => ['custom_dir'],
        ]);

        $this->assertNotNull($manager);
        $this->assertEquals(['custom_dir'], $manager->getSymlinkDirs());
    }

    // ── Create/Remove worktree (requires git) ─────────────────────

    public function testCreateAndRemoveWorktree(): void
    {
        $repoDir = $this->createTempGitRepo();
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir, symlinkDirs: []);

        // Create
        $info = $manager->create('test-agent', $repoDir, 'agent_001');

        $this->assertEquals('test-agent', $info->slug);
        $this->assertStringContainsString($this->tmpBaseDir, $info->path);
        $this->assertEquals('agent_' . 'test-agent', $info->branch);
        $this->assertEquals($repoDir, $info->originalPath);
        $this->assertEquals('agent_001', $info->agentId);
        $this->assertGreaterThan(0, $info->createdAt);
        $this->assertDirectoryExists($info->path);

        // Metadata saved
        $metaPath = $this->tmpBaseDir . '/test-agent.meta.json';
        $this->assertFileExists($metaPath);

        // Exists
        $this->assertTrue($manager->exists('test-agent'));

        // Get
        $retrieved = $manager->get('test-agent');
        $this->assertNotNull($retrieved);
        $this->assertEquals('test-agent', $retrieved->slug);

        // Remove
        $removed = $manager->remove('test-agent');
        $this->assertTrue($removed);
        $this->assertDirectoryDoesNotExist($info->path);
        $this->assertFileDoesNotExist($metaPath);
        $this->assertFalse($manager->exists('test-agent'));
    }

    public function testCreateWorktreeResumesExisting(): void
    {
        $repoDir = $this->createTempGitRepo();
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir, symlinkDirs: []);

        $info1 = $manager->create('resume-test', $repoDir);
        $info2 = $manager->create('resume-test', $repoDir);

        // Should reuse the same worktree
        $this->assertEquals($info1->path, $info2->path);

        $manager->remove('resume-test');
    }

    public function testCreateWorktreeWithSymlinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation requires elevated privileges on Windows.');
        }

        $repoDir = $this->createTempGitRepo();

        // Create a node_modules dir in the repo
        mkdir($repoDir . '/node_modules', 0755, true);
        file_put_contents($repoDir . '/node_modules/test.js', 'module.exports = {}');

        $manager = new WorktreeManager(
            baseDir: $this->tmpBaseDir,
            symlinkDirs: ['node_modules'],
        );

        $info = $manager->create('symlink-test', $repoDir);

        // Symlink should exist
        $linkPath = $info->path . '/node_modules';
        $this->assertTrue(is_link($linkPath) || is_dir($linkPath));

        $manager->remove('symlink-test');
    }

    public function testCreateWorktreeFailsOnNonGitDir(): void
    {
        $nonGitDir = sys_get_temp_dir() . '/sa_non_git_' . uniqid();
        mkdir($nonGitDir, 0755, true);

        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir, symlinkDirs: []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create worktree');

        try {
            $manager->create('bad-test', $nonGitDir);
        } finally {
            $this->recursiveDelete($nonGitDir);
        }
    }

    // ── List ──────────────────────────────────────────────────────

    public function testListWorktrees(): void
    {
        $repoDir = $this->createTempGitRepo();
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir, symlinkDirs: []);

        $manager->create('wt-1', $repoDir);
        $manager->create('wt-2', $repoDir);

        $list = $manager->list();
        $this->assertCount(2, $list);
        $slugs = array_map(fn($i) => $i->slug, $list);
        $this->assertContains('wt-1', $slugs);
        $this->assertContains('wt-2', $slugs);

        $manager->remove('wt-1');
        $manager->remove('wt-2');
    }

    public function testListEmptyDir(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);
        $list = $manager->list();
        $this->assertEmpty($list);
    }

    // ── Get ───────────────────────────────────────────────────────

    public function testGetNonexistent(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);
        $this->assertNull($manager->get('nonexistent'));
    }

    // ── Exists ────────────────────────────────────────────────────

    public function testExistsFalse(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);
        $this->assertFalse($manager->exists('nope'));
    }

    // ── Remove nonexistent ────────────────────────────────────────

    public function testRemoveNonexistent(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);
        $this->assertFalse($manager->remove('nope'));
    }

    // ── Prune ─────────────────────────────────────────────────────

    public function testPruneStaleMeta(): void
    {
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir);

        // Write stale metadata pointing to nonexistent dir
        $meta = [
            'slug' => 'stale-wt',
            'path' => '/nonexistent/path',
            'branch' => 'agent_stale',
            'original_path' => '/tmp',
            'agent_id' => null,
            'created_at' => time() - 86400,
        ];
        file_put_contents($this->tmpBaseDir . '/stale-wt.meta.json', json_encode($meta));

        $pruned = $manager->prune();
        $this->assertEquals(1, $pruned);
        $this->assertFileDoesNotExist($this->tmpBaseDir . '/stale-wt.meta.json');
    }

    public function testPruneKeepsValidMeta(): void
    {
        $repoDir = $this->createTempGitRepo();
        $manager = new WorktreeManager(baseDir: $this->tmpBaseDir, symlinkDirs: []);

        $info = $manager->create('valid-wt', $repoDir);

        $pruned = $manager->prune();
        $this->assertEquals(0, $pruned);

        $manager->remove('valid-wt');
    }

    // ── WorktreeInfo ──────────────────────────────────────────────

    public function testWorktreeInfoFromArray(): void
    {
        $info = WorktreeInfo::fromArray([
            'slug' => 'test',
            'path' => '/tmp/test',
            'branch' => 'agent_test',
            'original_path' => '/project',
            'agent_id' => 'ag_123',
            'created_at' => 1700000000,
        ]);

        $this->assertEquals('test', $info->slug);
        $this->assertEquals('/tmp/test', $info->path);
        $this->assertEquals('agent_test', $info->branch);
        $this->assertEquals('/project', $info->originalPath);
        $this->assertEquals('ag_123', $info->agentId);
        $this->assertEquals(1700000000, $info->createdAt);
    }

    public function testWorktreeInfoToArray(): void
    {
        $info = new WorktreeInfo('s', '/p', 'b', '/o', 'a', 123);
        $arr = $info->toArray();

        $this->assertEquals('s', $arr['slug']);
        $this->assertEquals('/p', $arr['path']);
        $this->assertEquals('b', $arr['branch']);
        $this->assertEquals('/o', $arr['original_path']);
        $this->assertEquals('a', $arr['agent_id']);
        $this->assertEquals(123, $arr['created_at']);
    }

    public function testWorktreeInfoRoundtrip(): void
    {
        $original = new WorktreeInfo('slug', '/path', 'branch', '/orig', 'agent', 999);
        $restored = WorktreeInfo::fromArray($original->toArray());

        $this->assertEquals($original->slug, $restored->slug);
        $this->assertEquals($original->path, $restored->path);
        $this->assertEquals($original->agentId, $restored->agentId);
    }

    // ── Helper ────────────────────────────────────────────────────

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
