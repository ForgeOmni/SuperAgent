<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use SuperAgent\Skills\SkillManager;

class UserSkillsDirTest extends TestCase
{
    private string $tmpHome;
    private string $skillsDir;
    private ?string $origHome;
    private ?string $origProfile;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/superagent_skills_' . bin2hex(random_bytes(6));
        $this->skillsDir = $this->tmpHome . '/.superagent/skills';
        @mkdir($this->skillsDir, 0755, true);

        $this->origHome = getenv('HOME') ?: null;
        $this->origProfile = getenv('USERPROFILE') ?: null;
        putenv('HOME=' . $this->tmpHome);
        putenv('USERPROFILE=' . $this->tmpHome);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->skillsDir . '/*') ?: []) as $f) {
            @unlink($f);
        }
        @rmdir($this->skillsDir);
        @rmdir($this->tmpHome . '/.superagent');
        @rmdir($this->tmpHome);

        putenv('HOME' . ($this->origHome === null ? '' : '=' . $this->origHome));
        putenv('USERPROFILE' . ($this->origProfile === null ? '' : '=' . $this->origProfile));
    }

    public function test_user_skills_dir_path_is_under_superagent_folder(): void
    {
        $path = SkillManager::userSkillsDir();
        $this->assertStringContainsString('.superagent', str_replace('\\', '/', $path));
        $this->assertStringEndsWith('skills', $path);
        $this->assertStringStartsWith($this->tmpHome, $path);
    }

    public function test_load_user_dir_picks_up_markdown_skills(): void
    {
        file_put_contents($this->skillsDir . '/greet.md', <<<'MD'
        ---
        name: greet
        description: A friendly greeting skill
        category: user
        ---
        Say hello to the user by name.
        MD);

        $manager = new SkillManager();
        $manager->loadUserDir();

        $skill = $manager->get('greet');
        $this->assertNotNull($skill);
        $this->assertSame('greet', $skill->name());
        $this->assertSame('A friendly greeting skill', $skill->description());
        $this->assertStringContainsString('hello', $skill->template());
    }

    public function test_load_user_dir_is_noop_when_dir_missing(): void
    {
        // Remove the dir so loadUserDir has nothing to read.
        @rmdir($this->skillsDir);
        @rmdir($this->tmpHome . '/.superagent');

        $manager = new SkillManager();
        $before = count($manager->getAll());
        $manager->loadUserDir();
        $this->assertSame($before, count($manager->getAll()));
    }

    public function test_malformed_markdown_is_skipped_silently(): void
    {
        // Missing frontmatter `name` → skill load should be silently skipped.
        file_put_contents($this->skillsDir . '/bad.md', <<<'MD'
        ---
        description: Has no name key
        ---
        Body.
        MD);

        $manager = new SkillManager();
        $manager->loadUserDir();

        // Should not have "bad" registered, and should not throw.
        $this->assertNull($manager->get('bad'));
    }

    public function test_project_skills_dir_accepts_explicit_root(): void
    {
        $projectRoot = sys_get_temp_dir() . '/superagent_project_' . bin2hex(random_bytes(6));
        $projectSkills = $projectRoot . '/.superagent/skills';
        @mkdir($projectSkills, 0755, true);

        file_put_contents($projectSkills . '/ship.md', <<<'MD'
        ---
        name: ship-it
        description: Project release checklist
        ---
        Cut a release.
        MD);

        try {
            $manager = new SkillManager();
            $manager->loadProjectDir($projectRoot);
            $this->assertNotNull($manager->get('ship-it'));
        } finally {
            @unlink($projectSkills . '/ship.md');
            @rmdir($projectSkills);
            @rmdir($projectRoot . '/.superagent');
            @rmdir($projectRoot);
        }
    }
}
