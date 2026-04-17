<?php

namespace SuperAgent\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperAgent\Skills\Skill;
use SuperAgent\Skills\SkillManager;

class SkillLoadingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        SkillManager::reset();
        $this->tempDir = sys_get_temp_dir() . '/superagent_skill_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $this->removeDir($this->tempDir);
        SkillManager::reset();
    }

    public function test_load_from_directory_resolves_namespace_from_file(): void
    {
        $this->writeSkillFile($this->tempDir . '/GreetSkill.php', 'TestSkills', 'GreetSkill', 'greet');

        $manager = SkillManager::getInstance();
        $manager->loadFromDirectory($this->tempDir);

        $skill = $manager->get('greet');
        $this->assertNotNull($skill);
        $this->assertEquals('greet', $skill->name());
    }

    public function test_load_from_directory_with_custom_namespace(): void
    {
        $this->writeSkillFile($this->tempDir . '/TranslateSkill.php', 'MyPlugin\\Skills', 'TranslateSkill', 'translate');

        $manager = SkillManager::getInstance();
        $manager->loadFromDirectory($this->tempDir);

        $skill = $manager->get('translate');
        $this->assertNotNull($skill);
        $this->assertEquals('translate', $skill->name());
    }

    public function test_load_from_directory_recursive(): void
    {
        $subDir = $this->tempDir . '/sub';
        mkdir($subDir, 0755, true);

        $this->writeSkillFile($this->tempDir . '/TopSkill.php', 'Top', 'TopSkill', 'top_skill');
        $this->writeSkillFile($subDir . '/NestedSkill.php', 'Top\\Sub', 'NestedSkill', 'nested_skill');

        $manager = SkillManager::getInstance();
        $manager->loadFromDirectory($this->tempDir, recursive: true);

        $this->assertNotNull($manager->get('top_skill'));
        $this->assertNotNull($manager->get('nested_skill'));
    }

    public function test_load_from_directory_non_recursive_skips_subdirs(): void
    {
        $subDir = $this->tempDir . '/sub';
        mkdir($subDir, 0755, true);

        $this->writeSkillFile($this->tempDir . '/FlatOnlySkill.php', 'FlatOnly', 'FlatOnlySkill', 'flat_only');
        $this->writeSkillFile($subDir . '/DeepOnlySkill.php', 'FlatOnly\\Sub', 'DeepOnlySkill', 'deep_only');

        $manager = SkillManager::getInstance();
        $manager->loadFromDirectory($this->tempDir, recursive: false);

        $this->assertNotNull($manager->get('flat_only'));
        $this->assertNull($manager->get('deep_only'));
    }

    public function test_load_from_file(): void
    {
        $file = $this->tempDir . '/SingleSkill.php';
        $this->writeSkillFile($file, 'Isolated', 'SingleSkill', 'single');

        $manager = SkillManager::getInstance();
        $manager->loadFromFile($file);

        $this->assertNotNull($manager->get('single'));
    }

    public function test_load_from_file_not_found_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Skill file not found');

        $manager = SkillManager::getInstance();
        $manager->loadFromFile('/nonexistent/FakeSkill.php');
    }

    public function test_load_from_file_not_a_skill_throws(): void
    {
        $file = $this->tempDir . '/NotASkill.php';
        file_put_contents($file, <<<'PHP'
<?php
namespace TestNotSkill;
class NotASkill {
    public function name(): string { return 'not_a_skill'; }
}
PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a Skill subclass');

        $manager = SkillManager::getInstance();
        $manager->loadFromFile($file);
    }

    public function test_load_from_nonexistent_directory_is_noop(): void
    {
        $manager = SkillManager::getInstance();
        $countBefore = count($manager->getAll());

        $manager->loadFromDirectory('/nonexistent/path');

        $this->assertCount($countBefore, $manager->getAll());
    }

    public function test_load_skips_non_skill_php_files(): void
    {
        // File that matches *Skill.php but contains a non-Skill class
        file_put_contents($this->tempDir . '/FakeSkill.php', <<<'PHP'
<?php
namespace TestFake;
class FakeSkill {
    public function hello(): string { return 'hi'; }
}
PHP);

        $manager = SkillManager::getInstance();
        $countBefore = count($manager->getAll());

        $manager->loadFromDirectory($this->tempDir);

        // Should not have added anything
        $this->assertCount($countBefore, $manager->getAll());
    }

    private function writeSkillFile(string $path, string $namespace, string $className, string $skillName): void
    {
        $content = <<<PHP
<?php
namespace {$namespace};

use SuperAgent\Skills\Skill;

class {$className} extends Skill
{
    public function name(): string { return '{$skillName}'; }
    public function description(): string { return 'Test skill {$skillName}'; }
    public function template(): string { return 'Template for {$skillName}'; }
}
PHP;
        file_put_contents($path, $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
