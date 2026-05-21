<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use SuperAgent\Skills\SkillManager;

class SkillDiscoveryTest extends TestCase
{
    private string $worktree;
    private string $subdir;

    protected function setUp(): void
    {
        $this->worktree = sys_get_temp_dir() . '/sa-skill-disc-' . bin2hex(random_bytes(4));
        $this->subdir = $this->worktree . '/packages/app/src';
        mkdir($this->subdir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->worktree);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    private function writeSkill(string $relativePath, string $name): void
    {
        $abs = $this->worktree . '/' . $relativePath;
        $dir = dirname($abs);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($abs, "---\nname: {$name}\ndescription: a test skill\n---\n\nbody");
    }

    public function test_discovers_external_claude_skill_in_worktree(): void
    {
        $this->writeSkill('.claude/skills/my-skill/SKILL.md', 'my-skill');

        $mgr = new SkillManager(autoLoadDisk: false);
        $loaded = $mgr->discoverExternalSkills($this->subdir, $this->worktree);
        $this->assertCount(1, $loaded);
        $this->assertNotNull($mgr->get('my-skill'));
    }

    public function test_discovers_agents_dir(): void
    {
        $this->writeSkill('.agents/skills/refactor/SKILL.md', 'refactor');

        $mgr = new SkillManager(autoLoadDisk: false);
        $loaded = $mgr->discoverExternalSkills($this->subdir, $this->worktree);
        $this->assertCount(1, $loaded);
        $this->assertNotNull($mgr->get('refactor'));
    }

    public function test_discovers_at_each_walk_level(): void
    {
        $this->writeSkill('.claude/skills/root-skill/SKILL.md', 'root-skill');
        $this->writeSkill('packages/.claude/skills/mid-skill/SKILL.md', 'mid-skill');
        $this->writeSkill('packages/app/.claude/skills/leaf-skill/SKILL.md', 'leaf-skill');

        $mgr = new SkillManager(autoLoadDisk: false);
        $loaded = $mgr->discoverExternalSkills($this->subdir, $this->worktree);
        $this->assertCount(3, $loaded);
        $this->assertNotNull($mgr->get('root-skill'));
        $this->assertNotNull($mgr->get('mid-skill'));
        $this->assertNotNull($mgr->get('leaf-skill'));
    }

    public function test_discovers_project_root_skills_and_skill_dirs(): void
    {
        $this->writeSkill('skills/explore/SKILL.md', 'explore');
        $this->writeSkill('skill/build/SKILL.md', 'build-skill');

        $mgr = new SkillManager(autoLoadDisk: false);
        $loaded = $mgr->discoverExternalSkills($this->worktree, $this->worktree);
        $this->assertCount(2, $loaded);
        $this->assertNotNull($mgr->get('explore'));
        $this->assertNotNull($mgr->get('build-skill'));
    }

    public function test_only_loads_files_literally_named_skill_md(): void
    {
        $this->writeSkill('.claude/skills/x/SKILL.md', 'real-skill');
        // A markdown file with the wrong name must NOT be loaded.
        file_put_contents($this->worktree . '/.claude/skills/x/readme.md', "---\nname: imposter\ndescription: x\n---\nbody");

        $mgr = new SkillManager(autoLoadDisk: false);
        $loaded = $mgr->discoverExternalSkills($this->subdir, $this->worktree);
        $this->assertCount(1, $loaded);
        $this->assertNotNull($mgr->get('real-skill'));
        $this->assertNull($mgr->get('imposter'));
    }

    public function test_does_not_walk_above_worktree(): void
    {
        // Put a skill OUTSIDE the worktree (parent of $worktree); the walk
        // must stop at $worktree and not descend into the parent.
        $parent = dirname($this->worktree);
        $parentSkillDir = $parent . '/.claude/skills/should-not-load';
        mkdir($parentSkillDir, 0777, true);
        $parentSkill = $parentSkillDir . '/SKILL.md';
        file_put_contents($parentSkill, "---\nname: should-not-load\ndescription: x\n---\nbody");

        try {
            $mgr = new SkillManager(autoLoadDisk: false);
            $loaded = $mgr->discoverExternalSkills($this->subdir, $this->worktree);
            $this->assertCount(0, $loaded);
            $this->assertNull($mgr->get('should-not-load'));
        } finally {
            unlink($parentSkill);
            rmdir($parentSkillDir);
            // Walk up and clean parent ancestors only if empty.
            $up = $parent . '/.claude/skills';
            if (is_dir($up) && (scandir($up) ?: []) === ['.', '..']) {
                rmdir($up);
            }
            $up = $parent . '/.claude';
            if (is_dir($up) && (scandir($up) ?: []) === ['.', '..']) {
                rmdir($up);
            }
        }
    }
}
