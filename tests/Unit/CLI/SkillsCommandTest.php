<?php

declare(strict_types=1);

namespace SuperAgent\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use SuperAgent\CLI\Commands\SkillsCommand;
use SuperAgent\Skills\SkillManager;

class SkillsCommandTest extends TestCase
{
    private string $tmpHome;
    private ?string $origHome;
    private ?string $origProfile;

    protected function setUp(): void
    {
        $this->tmpHome = sys_get_temp_dir() . '/superagent_skillscli_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpHome, 0755, true);
        $this->origHome = getenv('HOME') ?: null;
        $this->origProfile = getenv('USERPROFILE') ?: null;
        putenv('HOME=' . $this->tmpHome);
        putenv('USERPROFILE=' . $this->tmpHome);
    }

    protected function tearDown(): void
    {
        $dir = SkillManager::userSkillsDir();
        foreach ((glob($dir . '/*') ?: []) as $f) @unlink($f);
        @rmdir($dir);
        @rmdir(dirname($dir));
        @rmdir($this->tmpHome);

        putenv('HOME' . ($this->origHome === null ? '' : '=' . $this->origHome));
        putenv('USERPROFILE' . ($this->origProfile === null ? '' : '=' . $this->origProfile));
    }

    public function test_list_on_empty_lists_builtin_skills(): void
    {
        ob_start();
        $code = (new SkillsCommand())->execute(['skills_args' => ['list']]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        // Built-in skills are always there.
        $this->assertStringContainsString('refactor', $out);
    }

    public function test_path_prints_user_skills_dir(): void
    {
        ob_start();
        $code = (new SkillsCommand())->execute(['skills_args' => ['path']]);
        $out = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('skills', $out);
        $this->assertStringContainsString($this->tmpHome, trim($out));
    }

    public function test_install_copies_markdown_skill_to_user_dir(): void
    {
        $src = sys_get_temp_dir() . '/source_skill_' . bin2hex(random_bytes(6)) . '.md';
        file_put_contents($src, <<<'MD'
        ---
        name: my-test-skill
        description: Phase 6 install test
        ---
        Do the test thing.
        MD);

        try {
            ob_start();
            $code = (new SkillsCommand())->execute([
                'skills_args' => ['install', $src],
            ]);
            ob_end_clean();

            $this->assertSame(0, $code);
            $installed = glob(SkillManager::userSkillsDir() . '/*.md') ?: [];
            $this->assertNotEmpty($installed);

            // Skill is discoverable via a fresh manager.
            $manager = new SkillManager();
            $manager->loadUserDir();
            $this->assertNotNull($manager->get('my-test-skill'));
        } finally {
            @unlink($src);
        }
    }

    public function test_install_rejects_missing_frontmatter_name(): void
    {
        $src = sys_get_temp_dir() . '/bad_' . bin2hex(random_bytes(6)) . '.md';
        file_put_contents($src, <<<'MD'
        ---
        description: no name
        ---
        Body.
        MD);

        try {
            ob_start();
            $code = (new SkillsCommand())->execute([
                'skills_args' => ['install', $src],
            ]);
            ob_end_clean();
            $this->assertSame(1, $code);
            $this->assertSame([], glob(SkillManager::userSkillsDir() . '/*.md') ?: []);
        } finally {
            @unlink($src);
        }
    }

    public function test_install_rejects_unsupported_extension(): void
    {
        $src = sys_get_temp_dir() . '/ext_' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($src, 'some text');
        try {
            ob_start();
            $code = (new SkillsCommand())->execute([
                'skills_args' => ['install', $src],
            ]);
            ob_end_clean();
            $this->assertSame(2, $code);
        } finally {
            @unlink($src);
        }
    }

    public function test_remove_deletes_matching_markdown_skill(): void
    {
        $dir = SkillManager::userSkillsDir();
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/alpha.md', "---\nname: alpha\n---\nA\n");
        file_put_contents($dir . '/beta.md',  "---\nname: beta\n---\nB\n");

        ob_start();
        $code = (new SkillsCommand())->execute(['skills_args' => ['remove', 'alpha']]);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileDoesNotExist($dir . '/alpha.md');
        $this->assertFileExists($dir . '/beta.md');
    }

    public function test_remove_unknown_skill_is_zero_exit_with_warning(): void
    {
        ob_start();
        $code = (new SkillsCommand())->execute(['skills_args' => ['remove', 'doesnt-exist']]);
        ob_end_clean();
        $this->assertSame(0, $code);
    }

    public function test_show_prints_skill_body(): void
    {
        $dir = SkillManager::userSkillsDir();
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/demo.md',
            "---\nname: demo-skill\ndescription: Demo\n---\nPayload body here.\n"
        );

        ob_start();
        $code = (new SkillsCommand())->execute(['skills_args' => ['show', 'demo-skill']]);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('demo-skill', $out);
        $this->assertStringContainsString('Payload body here', $out);
    }

    public function test_show_unknown_returns_1(): void
    {
        ob_start();
        $code = (new SkillsCommand())->execute(['skills_args' => ['show', 'nope']]);
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    public function test_unknown_subcommand_returns_2(): void
    {
        ob_start();
        $code = (new SkillsCommand())->execute(['skills_args' => ['whatever']]);
        ob_end_clean();
        $this->assertSame(2, $code);
    }
}
